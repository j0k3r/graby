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
class HttpClientConfig
{
    use ArrayStringOptionsTrait;

    private string $ua_browser;
    private string $default_referer;
    /** @var array<array<string, string>> */
    private array $rewrite_url;
    /** @var array<string> */
    private array $header_only_types;
    /** @var array<string> */
    private array $header_only_clues;
    /** @var array<string, string> Mapping from hostnames to user agent strings */
    private array $user_agents;
    /** @var array<string> */
    private array $ajax_triggers;
    private int $max_redirect;

    /**
     * @param array{
     *   ua_browser?: string,
     *   default_referer?: string,
     *   rewrite_url?: array<array<string, string>>,
     *   header_only_types?: array<string>,
     *   header_only_clues?: array<string>,
     *   user_agents?: array<string, string>,
     *   ajax_triggers?: array<string>,
     *   max_redirect?: int,
     * } $config
     */
    public function __construct(array $config)
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'ua_browser' => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/535.2 (KHTML, like Gecko) Chrome/15.0.874.92 Safari/535.2',
            'default_referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
            'rewrite_url' => [
                'docs.google.com' => ['/Doc?' => '/View?'],
                'tnr.com' => ['tnr.com/article/' => 'tnr.com/print/article/'],
                '.m.wikipedia.org' => ['.m.wikipedia.org' => '.wikipedia.org'],
                'm.vanityfair.com' => ['m.vanityfair.com' => 'www.vanityfair.com'],
            ],
            // Prevent certain file/mime types
            // HTTP responses which match these content types will
            // be returned without body.
            'header_only_types' => [
                'image',
                'audio',
                'video',
            ],
            // URLs ending with one of these extensions will
            // prompt client to send a HEAD request first
            // to see if returned content type matches $headerOnlyTypes.
            'header_only_clues' => ['mp3', 'zip', 'exe', 'gif', 'gzip', 'gz', 'jpeg', 'jpg', 'mpg', 'mpeg', 'png', 'ppt', 'mov'],
            // User Agent strings - mapping domain names
            'user_agents' => [],
            // AJAX triggers to search for.
            // for AJAX sites, e.g. Blogger with its dynamic views templates.
            'ajax_triggers' => [
                "<meta name='fragment' content='!'",
                '<meta name="fragment" content="!"',
                "<meta content='!' name='fragment'",
                '<meta content="!" name="fragment"',
            ],
            // number of redirection allowed until we assume request won't be complete
            'max_redirect' => 10,
        ]);

        $resolver->setAllowedTypes('ua_browser', 'string');
        $resolver->setAllowedTypes('default_referer', 'string');
        $resolver->setAllowedTypes('rewrite_url', 'array');
        $resolver->setAllowedTypes('header_only_types', 'string[]');
        $resolver->setAllowedTypes('header_only_clues', 'string[]');
        $resolver->setAllowedTypes('user_agents', 'array');
        $resolver->setAllowedTypes('ajax_triggers', 'string[]');
        $resolver->setAllowedTypes('max_redirect', 'int');

        $resolver->setNormalizer('user_agents', function (Options $options, $value) {
            $this->validateArray($value, 'user_agents');

            return $value;
        });
        $resolver->setNormalizer('rewrite_url', function (Options $options, $value) {
            foreach ($value as $url => $action) {
                if (!\is_string($url)) {
                    throw new InvalidOptionsException(\sprintf('The option "rewrite_url" with key "%s" is expected to be of type "string", but is of type "%s".', $url, get_debug_type($url)));
                }

                $this->validateArray($action, 'rewrite_url[' . $url . ']');
            }

            return $value;
        });

        $config = $resolver->resolve($config);

        foreach ($config as $key => $value) {
            $this->$key = $value;
        }
    }

    public function getUaBrowser(): string
    {
        return $this->ua_browser;
    }

    public function getDefaultReferer(): string
    {
        return $this->default_referer;
    }

    /**
     * @return array<array<string, string>>
     */
    public function getRewriteUrl(): array
    {
        return $this->rewrite_url;
    }

    /**
     * @return array<string>
     */
    public function getHeaderOnlyTypes(): array
    {
        return $this->header_only_types;
    }

    /**
     * @return array<string>
     */
    public function getHeaderOnlyClues(): array
    {
        return $this->header_only_clues;
    }

    /**
     * @return array<string, string> Mapping from hostnames to user agent strings
     */
    public function getUserAgents(): array
    {
        return $this->user_agents;
    }

    /**
     * @return array<string>
     */
    public function getAjaxTriggers(): array
    {
        return $this->ajax_triggers;
    }

    public function getMaxRedirect(): int
    {
        return $this->max_redirect;
    }
}
