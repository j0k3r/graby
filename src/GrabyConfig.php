<?php

declare(strict_types=1);

namespace Graby;

use Graby\Config\ContentLinks;
use Graby\Config\ContentTypeAction;
use Graby\Config\LogLevel;
use Graby\Extractor\HttpClientConfig;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Configuration for Graby as a Value Object.
 */
readonly class GrabyConfig
{
    public function __construct(
        public bool $debug = false,
        public LogLevel $logLevel = LogLevel::Info,
        public bool $rewriteRelativeUrls = true,
        public bool $singlepage = true,
        public bool $multipage = true,
        public string $errorMessage = '[unable to retrieve full-text content]',
        public string $errorMessageTitle = 'No title found',
        /** @var array<string> */
        public array $allowedUrls = [],
        /** @var array<string> */
        public array $blockedUrls = [],
        public bool $xssFilter = true,
        /** @var array<string, array{name: string, action: ContentTypeAction}> */
        public array $contentTypeExc = [
            'application/zip' => ['action' => ContentTypeAction::Link, 'name' => 'ZIP'],
            'application/pdf' => ['action' => ContentTypeAction::Link, 'name' => 'PDF'],
            'image' => ['action' => ContentTypeAction::Link, 'name' => 'Image'],
            'audio' => ['action' => ContentTypeAction::Link, 'name' => 'Audio'],
            'video' => ['action' => ContentTypeAction::Link, 'name' => 'Video'],
            'text/plain' => ['action' => ContentTypeAction::Link, 'name' => 'Plain text'],
        ],
        public ContentLinks $contentLinks = ContentLinks::Preserve,
        public HttpClientConfig $httpClient = new HttpClientConfig(),
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
        public array $extractor = [],
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
}
