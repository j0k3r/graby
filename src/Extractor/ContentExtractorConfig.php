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

    private const ALLOWED_PARSERS = ['libxml', 'html5lib'];

    private string $default_parser;
    /** @var array<string, string> */
    private array $fingerprints;

    /**
     * @var array{
     *   site_config?: string[],
     *   hostname_regex?: string,
     * }
     */
    private array $config_builder;

    /**
     * @var array{
     *   pre_filters: array<string, string>,
     *   post_filters: array<string, string>,
     * }
     */
    private array $readability;

    /** @var array<string> */
    private array $src_lazy_load_attributes;
    /** @var array<string> */
    private array $json_ld_ignore_types;

    /**
     * @param array{
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
     * } $config
     */
    public function __construct(array $config)
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'default_parser' => 'libxml',
            // key is fingerprint (fragment to find in HTML)
            // value is host name to use for site config lookup if fingerprint matches
            // \s* match anything INCLUDING new lines
            'fingerprints' => [
                '/\<meta\s*content=([\'"])blogger([\'"])\s*name=([\'"])generator([\'"])/i' => 'fingerprint.blogspot.com',
                '/\<meta\s*name=([\'"])generator([\'"])\s*content=([\'"])Blogger([\'"])/i' => 'fingerprint.blogspot.com',
                '/\<meta\s*name=([\'"])generator([\'"])\s*content=([\'"])WordPress/i' => 'fingerprint.wordpress.com',
                '/\<meta\s*data-rh=([\'"])true([\'"])\s*property=([\'"])al:ios:app_name([\'"])\s*content=([\'"])Medium([\'"])/i' => 'fingerprint.medium.com',
                '/\<script\>.*\{([\'"])de\.ippen-digital\.story\.onlineId([\'"])/i' => 'fingerprint.ippen.media',
                '/\<link\s*rel=([\'"])stylesheet([\'"])\s*type=([\'"])text\/css([\'"])\s*href=([\'"])https:\/\/substackcdn\.com\//' => 'fingerprint.substack.com',
            ],
            'config_builder' => [],
            'readability' => [
                'pre_filters' => [],
                'post_filters' => [],
            ],
            'src_lazy_load_attributes' => [
                'data-src',
                'data-lazy-src',
                'data-original',
                'data-sources',
                'data-hi-res-src',
                'data-srcset',
            ],
            'json_ld_ignore_types' => ['Organization', 'WebSite', 'Person', 'VideoGame'],
        ]);

        $resolver->setAllowedValues('default_parser', self::ALLOWED_PARSERS);

        $resolver->setAllowedTypes('default_parser', 'string');
        $resolver->setAllowedTypes('fingerprints', 'array');
        $resolver->setAllowedTypes('config_builder', 'array');
        $resolver->setAllowedTypes('src_lazy_load_attributes', 'string[]');
        $resolver->setAllowedTypes('json_ld_ignore_types', 'string[]');

        $resolver->setDefault('readability', function (OptionsResolver $readabilityResolver): void {
            $readabilityResolver->setDefaults([
                'pre_filters' => [],
                'post_filters' => [],
            ]);
            $readabilityResolver->setAllowedTypes('pre_filters', 'array');
            $readabilityResolver->setAllowedTypes('post_filters', 'array');
        });

        $resolver->setNormalizer('readability', function (Options $options, $value) {
            $this->validateArray($value, 'readability[pre_filters]', 'pre_filters');
            $this->validateArray($value, 'readability[post_filters]', 'post_filters');

            return $value;
        });
        $resolver->setNormalizer('fingerprints', function (Options $options, $value) {
            $this->validateArray($value, 'fingerprints');

            return $value;
        });

        $config = $resolver->resolve($config);

        foreach ($config as $key => $value) {
            $this->$key = $value;
        }
    }

    public function getDefaultParser(): string
    {
        return $this->default_parser;
    }

    /**
     * @return array<string>
     */
    public function getAllowedParsers(): array
    {
        return self::ALLOWED_PARSERS;
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
        return $this->config_builder;
    }

    /**
     * @return array{
     *   pre_filters: array<string, string>,
     *   post_filters: array<string, string>,
     * }
     */
    public function getReadability(): array
    {
        return $this->readability;
    }

    /**
     * @return array<string>
     */
    public function getSrcLazyLoadAttributes(): array
    {
        return $this->src_lazy_load_attributes;
    }

    public function addSrcLazyLoadAttributes(string $attribute): void
    {
        $this->src_lazy_load_attributes[] = $attribute;
    }

    /**
     * @return array<string>
     */
    public function getJsonLdIgnoreTypes(): array
    {
        return $this->json_ld_ignore_types;
    }
}
