<?php

declare(strict_types=1);

namespace Graby\Extractor;

use Graby\OptionsResolver\ArrayStringOptionsTrait;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Configuration for HttpClient as a Value Object.
 */
readonly class HttpClientConfig
{
    use ArrayStringOptionsTrait;

    public function __construct(
        private string $uaBrowser = 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/535.2 (KHTML, like Gecko) Chrome/15.0.874.92 Safari/535.2',
        private string $defaultReferer = 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
        /** @var array<array<string, string>> */
        private array $rewriteUrl = [
            'docs.google.com' => ['/Doc?' => '/View?'],
            'tnr.com' => ['tnr.com/article/' => 'tnr.com/print/article/'],
            '.m.wikipedia.org' => ['.m.wikipedia.org' => '.wikipedia.org'],
            'm.vanityfair.com' => ['m.vanityfair.com' => 'www.vanityfair.com'],
        ],
        /**
         * @var array<string> prevent certain file/mime types
         *                    HTTP responses which match these content types will
         *                    be returned without body
         */
        private array $headerOnlyTypes = [
            'image',
            'audio',
            'video',
        ],
        /**
         * @var array<string> URLs ending with one of these extensions will
         *                    prompt client to send a HEAD request first
         *                    to see if returned content type matches $headerOnlyTypes
         */
        private array $headerOnlyClues = ['mp3', 'zip', 'exe', 'gif', 'gzip', 'gz', 'jpeg', 'jpg', 'mpg', 'mpeg', 'png', 'ppt', 'mov'],
        /** @var array<string, string> Mapping from hostnames to user agent strings */
        private array $userAgents = [],
        /**
         * @var array<string> AJAX triggers to search for.
         *                    for AJAX sites, e.g. Blogger with its dynamic views templates.
         */
        private array $ajaxTriggers = [
            "<meta name='fragment' content='!'",
            '<meta name="fragment" content="!"',
            "<meta content='!' name='fragment'",
            '<meta content="!" name="fragment"',
        ],
        /** @var int number of redirection allowed until we assume request won't be complete */
        private int $maxRedirect = 10,
    ) {
        $resolver = new OptionsResolver();
        $resolver->setDefined([
            'rewriteUrl',
            'headerOnlyTypes',
            'headerOnlyClues',
            'userAgents',
            'ajaxTriggers',
        ]);

        $resolver->setAllowedTypes('headerOnlyTypes', 'string[]');
        $resolver->setAllowedTypes('headerOnlyClues', 'string[]');
        $resolver->setAllowedTypes('ajaxTriggers', 'string[]');

        $resolver->setNormalizer('userAgents', function (Options $options, $value) {
            $this->validateArray($value, 'userAgents');

            return $value;
        });
        $resolver->setNormalizer('rewriteUrl', function (Options $options, $value) {
            foreach ($value as $url => $action) {
                if (!\is_string($url)) {
                    throw new InvalidOptionsException(\sprintf('The option "rewriteUrl" with key "%s" is expected to be of type "string", but is of type "%s".', $url, get_debug_type($url)));
                }

                $this->validateArray($action, 'rewriteUrl[' . $url . ']');
            }

            return $value;
        });

        $config = $resolver->resolve([
            'rewriteUrl' => $rewriteUrl,
            'headerOnlyTypes' => $headerOnlyTypes,
            'headerOnlyClues' => $headerOnlyClues,
            'userAgents' => $userAgents,
            'ajaxTriggers' => $ajaxTriggers,
        ]);
    }

    public function getUaBrowser(): string
    {
        return $this->uaBrowser;
    }

    public function getDefaultReferer(): string
    {
        return $this->defaultReferer;
    }

    /**
     * @return array<array<string, string>>
     */
    public function getRewriteUrl(): array
    {
        return $this->rewriteUrl;
    }

    /**
     * @return array<string>
     */
    public function getHeaderOnlyTypes(): array
    {
        return $this->headerOnlyTypes;
    }

    /**
     * @return array<string>
     */
    public function getHeaderOnlyClues(): array
    {
        return $this->headerOnlyClues;
    }

    /**
     * @return array<string, string> Mapping from hostnames to user agent strings
     */
    public function getUserAgents(): array
    {
        return $this->userAgents;
    }

    /**
     * @return array<string>
     */
    public function getAjaxTriggers(): array
    {
        return $this->ajaxTriggers;
    }

    public function getMaxRedirect(): int
    {
        return $this->maxRedirect;
    }
}
