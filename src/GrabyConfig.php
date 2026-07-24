<?php

declare(strict_types=1);

namespace Graby;

use Graby\Config\ContentLinks;
use Graby\Config\ContentTypeAction;
use Graby\Config\LogLevel;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Configuration for Graby as a Value Object.
 */
class GrabyConfig
{
    public function __construct(
        private readonly bool $debug = false,
        private readonly LogLevel $logLevel = LogLevel::Info,
        private readonly bool $rewriteRelativeUrls = true,
        private readonly bool $singlepage = true,
        private readonly bool $multipage = true,
        private readonly string $errorMessage = '[unable to retrieve full-text content]',
        private readonly string $errorMessageTitle = 'No title found',
        /** @var array<string> */
        private readonly array $allowedUrls = [],
        /** @var array<string> */
        private readonly array $blockedUrls = [],
        private readonly bool $xssFilter = true,
        /** @var array<string, array{name: string, action: ContentTypeAction}> */
        private readonly array $contentTypeExc = [
            'application/zip' => ['action' => ContentTypeAction::Link, 'name' => 'ZIP'],
            'application/pdf' => ['action' => ContentTypeAction::Link, 'name' => 'PDF'],
            'image' => ['action' => ContentTypeAction::Link, 'name' => 'Image'],
            'audio' => ['action' => ContentTypeAction::Link, 'name' => 'Audio'],
            'video' => ['action' => ContentTypeAction::Link, 'name' => 'Video'],
            'text/plain' => ['action' => ContentTypeAction::Link, 'name' => 'Plain text'],
        ],
        private readonly ContentLinks $contentLinks = ContentLinks::Preserve,
        /**
         * @var array{
         *   ua_browser?: string,
         *   default_referer?: string,
         *   rewrite_url?: array<array<string, string>>,
         *   header_only_types?: array<string>,
         *   header_only_clues?: array<string>,
         *   user_agents?: array<string, string>,
         *   ajax_triggers?: array<string>,
         *   max_redirect?: int,
         * }
         */
        private readonly array $httpClient = [],
        /**
         * @var array{
         *   default_parser?: string,
         *   fingerprints?: array<string, string>,
         *   config_builder?: array{
         *     site_config?: string[],
         *     hostname_regex?: string,
         *   },
         *   readability?: array{
         *     pre_filters?: array<string, string>,
         *     post_filters?: array<string, string>,
         *   },
         *   src_lazy_load_attributes?: string[],
         *   json_ld_ignore_types?: string[],
         * }
         */
        private readonly array $extractor = [],
    ) {
        $resolver = new OptionsResolver();

        $resolver->setDefined([
            'allowedUrls',
            'blockedUrls',
            'contentTypeExc',
        ]);

        $resolver->setAllowedTypes('allowedUrls', 'string[]');
        $resolver->setAllowedTypes('blockedUrls', 'string[]');

        $resolver->setNormalizer('contentTypeExc', static function (Options $options, $value) {
            $resolver = new OptionsResolver();
            $resolver->setRequired(['action', 'name']);
            $resolver->setAllowedTypes('action', ContentTypeAction::class);

            foreach ($value as $mime => $info) {
                $resolver->resolve($info);
            }

            return $value;
        });

        $config = $resolver->resolve([
            'allowedUrls' => $allowedUrls,
            'blockedUrls' => $blockedUrls,
            'contentTypeExc' => $contentTypeExc,
        ]);
    }

    public function getDebug(): bool
    {
        return $this->debug;
    }

    public function getLogLevel(): LogLevel
    {
        return $this->logLevel;
    }

    public function getRewriteRelativeUrls(): bool
    {
        return $this->rewriteRelativeUrls;
    }

    public function getSinglepage(): bool
    {
        return $this->singlepage;
    }

    public function getMultipage(): bool
    {
        return $this->multipage;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function getErrorMessageTitle(): string
    {
        return $this->errorMessageTitle;
    }

    /**
     * @return array<string>
     */
    public function getAllowedUrls(): array
    {
        return $this->allowedUrls;
    }

    /**
     * @return array<string>
     */
    public function getBlockedUrls(): array
    {
        return $this->blockedUrls;
    }

    public function getXssFilter(): bool
    {
        return $this->xssFilter;
    }

    /**
     * @return array<string, array{name: string, action: ContentTypeAction}>
     */
    public function getContentTypeExc(): array
    {
        return $this->contentTypeExc;
    }

    public function getContentLinks(): ContentLinks
    {
        return $this->contentLinks;
    }

    /**
     * @return array{
     *   ua_browser?: string,
     *   default_referer?: string,
     *   rewrite_url?: array<array<string, string>>,
     *   header_only_types?: array<string>,
     *   header_only_clues?: array<string>,
     *   user_agents?: array<string, string>,
     *   ajax_triggers?: array<string>,
     *   max_redirect?: int,
     * }
     */
    public function getHttpClient(): array
    {
        return $this->httpClient;
    }

    /**
     * @return array{
     *   default_parser?: string,
     *   fingerprints?: array<string, string>,
     *   config_builder?: array{
     *     site_config?: string[],
     *     hostname_regex?: string,
     *   },
     *   readability?: array{
     *     pre_filters?: array<string, string>,
     *     post_filters?: array<string, string>,
     *   },
     *   src_lazy_load_attributes?: string[],
     *   json_ld_ignore_types?: string[],
     * }
     */
    public function getExtractor(): array
    {
        return $this->extractor;
    }
}
