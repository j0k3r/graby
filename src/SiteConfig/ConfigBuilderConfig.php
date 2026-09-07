<?php

declare(strict_types=1);

namespace Graby\SiteConfig;

use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Configuration for ConfigBuilder as a Value Object.
 */
readonly class ConfigBuilderConfig
{
    /** @var string[] Directory paths of site config folders WITHOUT trailing slash */
    private array $siteConfig;

    /**
     * @param string[] $siteConfig
     */
    public function __construct(
        array $siteConfig = [],
        private string $hostnameRegex = '/^(([a-zA-Z0-9-]*[a-zA-Z0-9])\.)*([A-Za-z0-9-]*[A-Za-z0-9])$/',
    ) {
        $resolver = new OptionsResolver();
        $resolver->setDefined([
            'siteConfig',
        ]);

        $resolver->setAllowedTypes('siteConfig', 'string[]');

        $resolver->setNormalizer('siteConfig', static function (Options $options, $value) {
            foreach ($value as $key => $path) {
                $value[$key] = rtrim($path, '/');
            }

            return $value;
        });

        $config = $resolver->resolve([
            'siteConfig' => $siteConfig,
        ]);

        $this->siteConfig = $config['siteConfig'];
    }

    /**
     * @return array<string>
     */
    public function getSiteConfig(): array
    {
        return $this->siteConfig;
    }

    public function getHostnameRegex(): string
    {
        return $this->hostnameRegex;
    }
}
