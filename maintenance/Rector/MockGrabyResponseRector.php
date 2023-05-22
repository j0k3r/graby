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
use Psr\Http\Message\ResponseInterface;
use Rector\Core\Application\FileSystem\RemovedAndAddedFilesCollector;
use Rector\Core\Rector\AbstractRector;
use Rector\FileSystemRector\ValueObject\AddedFileWithContent;
use Rector\Naming\Naming\VariableNaming;
use Rector\NodeNestingScope\ParentScopeFinder;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PostRector\Collector\UseNodesToAddCollector;
use Rector\StaticTypeMapper\ValueObject\Type\AliasedObjectType;
use Rector\StaticTypeMapper\ValueObject\Type\FullyQualifiedObjectType;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Symplify\SmartFileSystem\SmartFileInfo;

final class MockGrabyResponseRector extends AbstractRector
{
    private const FIXTURE_DIRECTORY = __DIR__ . '/../../tests/fixtures/content';
    private const MATCHING_ERROR_COMMENT = 'TODO: Rector was unable to evaluate this Graby config.';
    private const IGNORE_COMMENT = 'Rector: do not add mock client';
    private ParentScopeFinder $parentScopeFinder;
    private RemovedAndAddedFilesCollector $removedAndAddedFilesCollector;
    private UseNodesToAddCollector $useNodesToAddCollector;
    private VariableNaming $variableNaming;

    public function __construct(
        ParentScopeFinder $parentScopeFinder,
        RemovedAndAddedFilesCollector $removedAndAddedFilesCollector,
        UseNodesToAddCollector $useNodesToAddCollector,
        VariableNaming $variableNaming
    ) {
        $this->parentScopeFinder = $parentScopeFinder;
        $this->removedAndAddedFilesCollector = $removedAndAddedFilesCollector;
        $this->useNodesToAddCollector = $useNodesToAddCollector;
        $this->variableNaming = $variableNaming;
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
        return [New_::class];
    }

    /**
     * @param New_ $node
     */
    public function refactor(Node $node): ?Node
    {
        $new = $node;
        if (!$this->nodeNameResolver->isName($new->class, 'Graby\Graby')) {
            return null;
        }

        /** @var Node $parentNode */
        $parentNode = $new->getAttribute(AttributeKey::PARENT_NODE);
        $assignment = $parentNode;
        if (!$assignment instanceof Assign) {
            return null;
        }

        $statement = $this->betterNodeFinder->resolveCurrentStatement($new);
        \assert(null !== $statement, 'Graby construction needs to be inside a statement.');

        $comments = $statement->getAttribute(AttributeKey::COMMENTS);
        if (null !== $comments) {
            foreach ($comments as $comment) {
                if (preg_match('(' . preg_quote(self::MATCHING_ERROR_COMMENT) . '|' . preg_quote(self::IGNORE_COMMENT) . ')', $comment->getText())) {
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

        $scope = $this->parentScopeFinder->find($new);
        if (null === $scope) {
            return null;
        }

        $fetchUrls = array_map(
            fn (Node $node): ?string => $this->getFetchUrl($grabyVariable, $node),
            $this->betterNodeFinder->find(
                (array) $scope->stmts,
                fn (Node $foundNode): bool => null !== $this->getFetchUrl($grabyVariable, $foundNode)
            )
        );

        if (1 !== \count($fetchUrls)) {
            // For simplicity only supporting single fetchContent call.
            return null;
        }

        $url = (string) $fetchUrls[0];

        // Add imports.
        $this->useNodesToAddCollector->addUseImport(
            new AliasedObjectType('HttpMockClient', 'Http\Mock\Client')
        );
        $this->useNodesToAddCollector->addUseImport(
            new FullyQualifiedObjectType('GuzzleHttp\Psr7\Response')
        );

        $httpMockClientVariable = $this->createMockClientVariable($new);
        // List of statements to be placed before the Graby construction.
        $mockStatements = [
            new Assign($httpMockClientVariable, new New_(new Name('HttpMockClient'))),
        ];

        $config = 0 === \count($new->args) || !($configArg = $new->args[0]) instanceof Arg ? [] : $this->valueResolver->getValue($configArg->value);
        if (null === $config) {
            // Paste the config here if this failed.
            $config = [];

            $statement->setAttribute(
                AttributeKey::COMMENTS,
                array_merge(
                    $comments ?? [],
                    [new Comment('// ' . self::MATCHING_ERROR_COMMENT)]
                )
            );

            return $new;
        }

        foreach ($this->fetchResponses($url, $config) as $index => $response) {
            $suffix = (0 !== $index ? '.' . $index : '') . preg_match('(\.[a-z0-9]+$)', $url) ? '' : '.html';
            $fileName = preg_replace('([^a-zA-Z0-9-_\.])', '_', $url) . $suffix;

            // Create a fixture.
            $this->removedAndAddedFilesCollector->addAddedFile(
                new AddedFileWithContent(
                    self::FIXTURE_DIRECTORY . '/' . $fileName,
                    (string) $response->getBody()
                )
            );

            // Register a mocked response.
            $mockStatements[] = $this->nodeFactory->createMethodCall(
                $httpMockClientVariable,
                'addResponse',
                [
                    $this->createNewResponseExpression($response, $fileName),
                ]
            );
        }

        $this->nodesToAddCollector->addNodesBeforeNode($mockStatements, $new);

        // Add the mocked client to Graby constructor.
        $new->args[] = new Arg($httpMockClientVariable);

        return $new;
    }

    /**
     * Provides a new variable called $httpMockClient,
     * optionally followed by number if the name is already taken.
     */
    private function createMockClientVariable(New_ $new): Variable
    {
        $currentStmt = $this->betterNodeFinder->resolveCurrentStatement($new);
        $scope = $currentStmt->getAttribute(AttributeKey::SCOPE);
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
        $fixtureDirectory = new SmartFileInfo(self::FIXTURE_DIRECTORY);
        $relativeFixturePath = $fixtureDirectory->getRelativeFilePathFromDirectory($this->file->getSmartFileInfo()->getRealPathDirectory());

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
