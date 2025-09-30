<?php

declare(strict_types=1);

namespace Maintenance\Graby\Rector;

use Graby\Graby;
use Graby\HttpClient\Plugin\CookiePlugin;
use Http\Client\Common\PluginClient;
use Http\Discovery\Psr18ClientDiscovery;
use Http\Message\CookieJar;
use Maintenance\Graby\Rector\Helpers\RecordingHttpClient;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use Psr\Http\Message\ResponseInterface;
use Rector\Naming\Naming\VariableNaming;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PhpParser\Node\BetterNodeFinder;
use Rector\PhpParser\Node\Value\ValueResolver;
use Rector\PostRector\Collector\UseNodesToAddCollector;
use Rector\Rector\AbstractRector;
use Rector\StaticTypeMapper\ValueObject\Type\FullyQualifiedObjectType;
use Symfony\Component\Filesystem\Path;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class MockGrabyResponseRector extends AbstractRector
{
    private const FIXTURE_DIRECTORY = __DIR__ . '/../../tests/fixtures/content';
    private const MATCHING_ERROR_COMMENT = 'TODO: Rector was unable to evaluate this Graby config.';
    private const IGNORE_COMMENT = 'Rector: do not add mock client';

    public function __construct(
        private readonly BetterNodeFinder $betterNodeFinder,
        private readonly UseNodesToAddCollector $useNodesToAddCollector,
        private readonly ValueResolver $valueResolver,
        private readonly VariableNaming $variableNaming
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Replace Graby instance by one with a mocked requests and stores the response in a fixture.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                        $graby = new Graby($config);
                        $res = $graby->fetchContent('http://example.com/foo');
                        CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
                        $httpMockClient = new HttpMockClient();
                        $httpMockClient->addResponse(new Response(301, ['Location' => 'https://example.com/'], (string) file_get_contents('/fixtures/content/http___example.com_foo.html')));
                        $httpMockClient->addResponse(new Response(200, [...], (string) file_get_contents('/fixtures/content/http___example.com_foo.html')));
                        $graby = new Graby($config, $httpMockClient);
                        $res = $graby->fetchContent('http://example.com/');
                        CODE_SAMPLE
                ),
            ]
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        // PHPUnit tests are class methods.
        return [ClassMethod::class];
    }

    /**
     * @param ClassMethod $node
     */
    public function refactor(Node $node): ?Node
    {
        $assigns = $this->betterNodeFinder->find(
            $node,
            fn (Node $node): bool => $node instanceof Expression
                && ($assignment = $node->expr) instanceof Assign
                && ($new = $assignment->expr) instanceof New_
                && $this->nodeNameResolver->isName($new->class, Graby::class)
        );

        if (1 !== \count($assigns)) {
            return null;
        }

        $assignStmt = $assigns[0];
        \assert($assignStmt instanceof Expression);

        $assignment = $assignStmt->expr;
        \assert($assignment instanceof Assign);

        $new = $assignment->expr;
        \assert($new instanceof New_);

        $comments = $assignStmt->getAttribute(AttributeKey::COMMENTS);
        if (null !== $comments) {
            foreach ($comments as $comment) {
                if (preg_match('(' . preg_quote(self::MATCHING_ERROR_COMMENT) . '|' . preg_quote(self::IGNORE_COMMENT) . ')', (string) $comment->getText())) {
                    // Skip already processed or intentionally excluded.
                    return null;
                }
            }
        }

        if (\count($new->args) > 1) {
            // The Graby instance is already passed a MockHttpClient.
            return null;
        }

        $grabyVariable = $assignment->var;
        if (!$grabyVariable instanceof Variable) {
            return null;
        }

        $stmts = (array) $node->stmts;

        $fetchUrls = array_map(
            fn (Node $node): ?string => $this->getFetchUrl($grabyVariable, $node),
            $this->betterNodeFinder->find(
                $stmts,
                fn (Node $foundNode): bool => null !== $this->getFetchUrl($grabyVariable, $foundNode)
            )
        );

        if (1 !== \count($fetchUrls)) {
            // For simplicity only supporting single fetchContent call.
            return null;
        }

        $url = (string) $fetchUrls[0];

        // Add imports.
        // `use Http\Mock\Client as HttpMockClient;` needs to be added manually.
        $this->useNodesToAddCollector->addUseImport(
            new FullyQualifiedObjectType(\GuzzleHttp\Psr7\Response::class)
        );

        $httpMockClientVariable = $this->createMockClientVariable($assignment);
        // List of statements to be placed before the Graby construction.
        $mockStatements = [
            new Expression(new Assign($httpMockClientVariable, new New_(new Name('HttpMockClient')))),
        ];

        $config = 0 === \count($new->args) || !($configArg = $new->args[0]) instanceof Arg ? [] : $this->valueResolver->getValue($configArg->value);
        if (null === $config) {
            // Paste the config here if this failed.
            $config = [];

            $assignStmt->setAttribute(
                AttributeKey::COMMENTS,
                array_merge(
                    $comments ?? [],
                    [new Comment('// ' . self::MATCHING_ERROR_COMMENT)]
                )
            );

            return $node;
        }

        foreach ($this->fetchResponses($url, $config) as $index => $response) {
            $suffix = (0 !== $index ? '.' . $index : '') . preg_match('(\.[a-z0-9]+$)', $url) ? '' : '.html';
            $fileName = preg_replace('([^a-zA-Z0-9-_\.])', '_', $url) . $suffix;

            // Create a fixture.
            file_put_contents(
                self::FIXTURE_DIRECTORY . '/' . $fileName,
                (string) $response->getBody()
            );

            // Register a mocked response.
            $mockStatements[] = new Expression($this->nodeFactory->createMethodCall(
                $httpMockClientVariable,
                'addResponse',
                [
                    $this->createNewResponseExpression($response, $fileName),
                ]
            ));
        }

        // Add the mocked client to Graby constructor.
        $new->args[] = new Arg($httpMockClientVariable);

        $index = array_search($assignStmt, $stmts, true);
        \assert(false !== $index, 'Assignment statement not direct child of the method');
        \assert(!\is_string($index)); // For PHPStan.

        array_splice($stmts, $index, 0, $mockStatements);
        $node->stmts = $stmts;

        return $node;
    }

    /**
     * Provides a new variable called $httpMockClient,
     * optionally followed by number if the name is already taken.
     */
    private function createMockClientVariable(Assign $assignment): Variable
    {
        $scope = $assignment->getAttribute(AttributeKey::SCOPE);
        \assert(null !== $scope); // For PHPStan.

        return new Variable($this->variableNaming->createCountedValueName('httpMockClient', $scope));
    }

    /**
     * Creates an expression constructing a Response object with same data as the given Response.
     * Assumes GuzzleHttp\Psr7\Response is imported and that the response contents
     * are stored at $fileName relative to the current directory.
     */
    private function createNewResponseExpression(ResponseInterface $response, string $fileName): New_
    {
        $relativeFixturePath = Path::makeRelative(self::FIXTURE_DIRECTORY, \dirname($this->file->getFilePath()));

        return new New_(
            new Name('Response'),
            $this->nodeFactory->createArgs([
                $response->getStatusCode(),
                $response->getHeaders(),
                new Cast\String_(
                    $this->nodeFactory->createFuncCall(
                        'file_get_contents',
                        [
                            new Concat(new ConstFetch(new Name('__DIR__')), new String_('/' . $relativeFixturePath . '/' . $fileName)),
                        ]
                    )
                ),
            ])
        );
    }

    /**
     * Runs Graby for given URL and returns HTTP responses by the client.
     *
     * @param array{
     *   debug?: bool,
     *   log_level?: 'info'|'debug',
     *   rewrite_relative_urls?: bool,
     *   singlepage?: bool,
     *   multipage?: bool,
     *   error_message?: string,
     *   error_message_title?: string,
     *   allowed_urls?: string[],
     *   blocked_urls?: string[],
     *   xss_filter?: bool,
     *   content_type_exc?: array<string, array{name: string, action: 'link'|'exclude'}>,
     *   content_links?: 'preserve'|'footnotes'|'remove',
     *   http_client?: array{
     *     ua_browser?: string,
     *     default_referer?: string,
     *     rewrite_url?: array<array<string, string>>,
     *     header_only_types?: array<string>,
     *     header_only_clues?: array<string>,
     *     user_agents?: array<string, string>,
     *     ajax_triggers?: array<string>,
     *     max_redirect?: int,
     *   },
     *   extractor?: array{
     *     default_parser?: string,
     *     fingerprints?: array<string, string>,
     *     config_builder?: array{
     *       site_config?: string[],
     *       hostname_regex?: string,
     *     },
     *     readability?: array{
     *       pre_filters?: array<string, string>,
     *       post_filters?: array<string, string>,
     *     },
     *     src_lazy_load_attributes?: string[],
     *     json_ld_ignore_types?: string[],
     *   },
     * } $config
     *
     * @return ResponseInterface[]
     */
    private function fetchResponses(string $url, array $config): array
    {
        // Wrap the same HTTP client internally created by Graby class with a proxy
        // that captures the returned responses.
        $httpClient = new RecordingHttpClient(new PluginClient(Psr18ClientDiscovery::find(), [new CookiePlugin(new CookieJar())]));
        $graby = new Graby($config, $httpClient);
        $graby->fetchContent($url);

        return $httpClient->getResponses();
    }

    /**
     * Extracts URL from the AST node in the form $graby->fetchContent('url').
     */
    private function getFetchUrl(Variable $grabyVariable, Node $node): ?string
    {
        $methodCall = $node;
        if (!$methodCall instanceof MethodCall) {
            return null;
        }

        if (!$this->nodeNameResolver->areNamesEqual($methodCall->var, $grabyVariable)) {
            return null;
        }

        if (!$methodCall->name instanceof Identifier || !$this->nodeNameResolver->isName($methodCall->name, 'fetchContent')) {
            return null;
        }

        $args = $methodCall->args;
        if (1 !== \count($args) || !$args[0] instanceof Arg || !$args[0]->value instanceof String_) {
            return null;
        }

        return $args[0]->value->value;
    }
}
