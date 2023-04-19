<?php

declare(strict_types=1);

namespace Graby\SiteConfig;

use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Configuration for ConfigBuilder as a Value Object.
 */
class ConfigBuilderConfig
{
    /** @var array<string> */
    private array $site_config;
    private string $hostname_regex;

    public function __construct(array $config)
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            // Directory path to the site config folder WITHOUT trailing slash
            'site_config' => [],
            'hostname_regex' => '/^(([a-zA-Z0-9-]*[a-zA-Z0-9])\.)*([A-Za-z0-9-]*[A-Za-z0-9])$/',
        ]);

        $resolver->setRequired('site_config');

        $resolver->setAllowedTypes('site_config', 'string[]');
        $resolver->setAllowedTypes('hostname_regex', 'string');

        $resolver->setNormalizer('site_config', function (Options $options, $value) {
            foreach ($value as $key => $path) {
                $value[$key] = rtrim($path, '/');
            }

            return $value;
        });

        $config = $resolver->resolve($config);

        foreach ($config as $key => $value) {
            $this->$key = $value;
        }
    }

    /**
     * @return array<string>
     */
    public function getSiteConfig(): array
    {
        return $this->site_config;
    }

    public function getHostnameRegex(): string
    {
        return $this->hostname_regex;
    }
}
