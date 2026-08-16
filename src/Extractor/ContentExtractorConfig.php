<?php

declare(strict_types=1);

namespace Graby\Extractor;

use Graby\OptionsResolver\ArrayStringOptionsTrait;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Configuration for ContentExtractor as a Value Object.
 */
readonly class ContentExtractorConfig
{
    use ArrayStringOptionsTrait;

    public function __construct(
        public Parser $defaultParser = Parser::Libxml,
        /**
         * @var array<string, string> key is fingerprint (fragment to find in HTML)
         *                            value is host name to use for site config lookup if fingerprint matches
         *                            \s* match anything INCLUDING new lines
         */
        public array $fingerprints = [
            '/\<meta\s*content=([\'"])blogger([\'"])\s*name=([\'"])generator([\'"])/i' => 'fingerprint.blogspot.com',
            '/\<meta\s*name=([\'"])generator([\'"])\s*content=([\'"])Blogger([\'"])/i' => 'fingerprint.blogspot.com',
            '/\<meta\s*name=([\'"])generator([\'"])\s*content=([\'"])WordPress/i' => 'fingerprint.wordpress.com',
            '/\<meta\s*data-rh=([\'"])true([\'"])\s*property=([\'"])al:ios:app_name([\'"])\s*content=([\'"])Medium([\'"])/i' => 'fingerprint.medium.com',
            '/\<script\>.*\{([\'"])de\.ippen-digital\.story\.onlineId([\'"])/i' => 'fingerprint.ippen.media',
            '/\<link\s*rel=([\'"])stylesheet([\'"])\s*type=([\'"])text\/css([\'"])\s*href=([\'"])https:\/\/substackcdn\.com\//' => 'fingerprint.substack.com',
        ],
        /**
         * @var array{
         *   site_config?: string[],
         *   hostname_regex?: string,
         * }
         */
        public array $configBuilder = [],
        /**
         * @var array{
         *   pre_filters: array<string, string>,
         *   post_filters: array<string, string>,
         * }
         */
        public array $readability = [
            'pre_filters' => [],
            'post_filters' => [],
        ],
        /** @var array<string> */
        public array $srcLazyLoadAttributes = [
            'data-src',
            'data-lazy-src',
            'data-original',
            'data-sources',
            'data-hi-res-src',
            'data-srcset',
        ],
        /** @var array<string> */
        public array $jsonLdIgnoreTypes = ['Organization', 'WebSite', 'Person', 'VideoGame'],
    ) {
        $resolver = new OptionsResolver();
        $resolver->setDefined([
            'fingerprints',
            'configBuilder',
            'readability',
            'srcLazyLoadAttributes',
            'jsonLdIgnoreTypes',
        ]);

        $resolver->setAllowedTypes('fingerprints', 'array');
        $resolver->setAllowedTypes('configBuilder', 'array');
        $resolver->setAllowedTypes('readability', 'array');
        $resolver->setAllowedTypes('srcLazyLoadAttributes', 'string[]');
        $resolver->setAllowedTypes('jsonLdIgnoreTypes', 'string[]');

        $resolver->setNormalizer('readability', function (Options $options, $value) {
            $readabilityResolver = new OptionsResolver();
            $readabilityResolver->setDefaults([
                'pre_filters' => [],
                'post_filters' => [],
            ]);
            $readabilityResolver->setAllowedTypes('pre_filters', 'array');
            $readabilityResolver->setAllowedTypes('post_filters', 'array');
            $value = $readabilityResolver->resolve($value);

            $this->validateArray($value, 'readability[pre_filters]', 'pre_filters');
            $this->validateArray($value, 'readability[post_filters]', 'post_filters');

            return $value;
        });
        $resolver->setNormalizer('fingerprints', function (Options $options, $value) {
            $this->validateArray($value, 'fingerprints');

            return $value;
        });

        $config = $resolver->resolve([
            'fingerprints' => $fingerprints,
            'configBuilder' => $configBuilder,
            'readability' => $readability,
            'srcLazyLoadAttributes' => $srcLazyLoadAttributes,
            'jsonLdIgnoreTypes' => $jsonLdIgnoreTypes,
        ]);
    }
}
