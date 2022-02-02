<?php

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
    private array $config_builder;
    private array $readability;
    /** @var array<string> */
    private array $src_lazy_load_attributes;

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
        ]);

        $resolver->setAllowedValues('default_parser', self::ALLOWED_PARSERS);

        $resolver->setAllowedTypes('default_parser', 'string');
        $resolver->setAllowedTypes('fingerprints', 'array');
        $resolver->setAllowedTypes('config_builder', 'array');
        $resolver->setAllowedTypes('src_lazy_load_attributes', 'string[]');

        $resolver->setDefault('readability', function (OptionsResolver $readabilityResolver) {
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

    public function getConfigBuilder(): array
    {
        return $this->config_builder;
    }

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
}
