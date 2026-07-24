<?php

declare(strict_types=1);

namespace Graby\Extractor;

use Graby\OptionsResolver\ArrayStringOptionsTrait;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Configuration for ContentExtractor as a Value Object.
 */
class ContentExtractorConfig
{
    use ArrayStringOptionsTrait;

    public function __construct(
        private Parser $defaultParser = Parser::Libxml,
        /**
         * @var array<string, string> key is fingerprint (fragment to find in HTML)
         *                            value is host name to use for site config lookup if fingerprint matches
         *                            \s* match anything INCLUDING new lines
         */
        private array $fingerprints = [
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
        private array $configBuilder = [],
        private ReadabilityConfig $readability = new ReadabilityConfig(),
        /** @var array<string> */
        private array $srcLazyLoadAttributes = [
            'data-src',
            'data-lazy-src',
            'data-original',
            'data-sources',
            'data-hi-res-src',
            'data-srcset',
        ],
        /** @var array<string> */
        private array $jsonLdIgnoreTypes = ['Organization', 'WebSite', 'Person', 'VideoGame'],
    ) {
        $resolver = new OptionsResolver();
        $resolver->setDefined([
            'fingerprints',
            'configBuilder',
            'srcLazyLoadAttributes',
            'jsonLdIgnoreTypes',
        ]);

        $resolver->setAllowedTypes('fingerprints', 'array');
        $resolver->setAllowedTypes('configBuilder', 'array');
        $resolver->setAllowedTypes('srcLazyLoadAttributes', 'string[]');
        $resolver->setAllowedTypes('jsonLdIgnoreTypes', 'string[]');

        $resolver->setNormalizer('fingerprints', function (Options $options, $value) {
            $this->validateArray($value, 'fingerprints');

            return $value;
        });

        $config = $resolver->resolve([
            'fingerprints' => $fingerprints,
            'configBuilder' => $configBuilder,
            'srcLazyLoadAttributes' => $srcLazyLoadAttributes,
            'jsonLdIgnoreTypes' => $jsonLdIgnoreTypes,
        ]);
    }

    public function getDefaultParser(): Parser
    {
        return $this->defaultParser;
    }

    /**
     * @return array<string, string>
     */
    public function getFingerprints(): array
    {
        return $this->fingerprints;
    }

    /**
     * @return array{
     *   site_config?: string[],
     *   hostname_regex?: string,
     * }
     */
    public function getConfigBuilder(): array
    {
        return $this->configBuilder;
    }

    public function getReadability(): ReadabilityConfig
    {
        return $this->readability;
    }

    /**
     * @return array<string>
     */
    public function getSrcLazyLoadAttributes(): array
    {
        return $this->srcLazyLoadAttributes;
    }

    public function addSrcLazyLoadAttributes(string $attribute): void
    {
        $this->srcLazyLoadAttributes[] = $attribute;
    }

    /**
     * @return array<string>
     */
    public function getJsonLdIgnoreTypes(): array
    {
        return $this->jsonLdIgnoreTypes;
    }
}
