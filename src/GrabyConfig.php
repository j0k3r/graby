<?php

declare(strict_types=1);

namespace Graby;

use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Configuration for Graby as a Value Object.
 */
class GrabyConfig
{
    private bool $debug;
    /** @var 'info'|'debug' */
    private string $log_level;
    private bool $rewrite_relative_urls;
    private bool $singlepage;
    private bool $multipage;
    private string $error_message;
    private string $error_message_title;
    /** @var array<string> */
    private array $allowed_urls;
    /** @var array<string> */
    private array $blocked_urls;
    private bool $xss_filter;
    /** @var array<string, array{name: string, action: 'link'|'exclude'}> */
    private array $content_type_exc;
    /** @var 'preserve'|'footnotes'|'remove' */
    private string $content_links;

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
    private array $http_client;

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
    private array $extractor;

    /**
     * @param array{
     *   debug?: bool,
     *   log_level?: 'info'|'debug',
     *   rewrite_relative_urls?: bool,
     *   singlepage?: bool,
     *   multipage?: bool,
     *   error_message?: string,
     *   error_message_title?: string,
     *   allowed_urls?: string[],
     *   blocked_urls?: string[],
     *   xss_filter?: bool,
     *   content_type_exc?: array<string, array{name: string, action: 'link'|'exclude'}>,
     *   content_links?: 'preserve'|'footnotes'|'remove',
     *   http_client?: array{
     *     ua_browser?: string,
     *     default_referer?: string,
     *     rewrite_url?: array<array<string, string>>,
     *     header_only_types?: array<string>,
     *     header_only_clues?: array<string>,
     *     user_agents?: array<string, string>,
     *     ajax_triggers?: array<string>,
     *     max_redirect?: int,
     *   },
     *   extractor?: array{
     *     default_parser?: string,
     *     fingerprints?: array<string, string>,
     *     config_builder?: array{
     *       site_config?: string[],
     *       hostname_regex?: string,
     *     },
     *     readability?: array{
     *       pre_filters?: array<string, string>,
     *       post_filters?: array<string, string>,
     *     },
     *     src_lazy_load_attributes?: string[],
     *     json_ld_ignore_types?: string[],
     *   },
     * } $config
     */
    public function __construct(array $config)
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'debug' => false,
            'log_level' => 'info',
            'rewrite_relative_urls' => true,
            'singlepage' => true,
            'multipage' => true,
            'error_message' => '[unable to retrieve full-text content]',
            'error_message_title' => 'No title found',
            'allowed_urls' => [],
            'blocked_urls' => [],
            'xss_filter' => true,
            'content_type_exc' => [
                'application/zip' => ['action' => 'link', 'name' => 'ZIP'],
                'application/pdf' => ['action' => 'link', 'name' => 'PDF'],
                'image' => ['action' => 'link', 'name' => 'Image'],
                'audio' => ['action' => 'link', 'name' => 'Audio'],
                'video' => ['action' => 'link', 'name' => 'Video'],
                'text/plain' => ['action' => 'link', 'name' => 'Plain text'],
            ],
            'content_links' => 'preserve',
            'http_client' => [],
            'extractor' => [],
        ]);

        $resolver->setAllowedValues('content_links', ['preserve', 'footnotes', 'remove']);
        $resolver->setAllowedValues('log_level', ['info', 'debug']);

        $resolver->setAllowedTypes('debug', 'bool');
        $resolver->setAllowedTypes('rewrite_relative_urls', 'bool');
        $resolver->setAllowedTypes('singlepage', 'bool');
        $resolver->setAllowedTypes('multipage', 'bool');
        $resolver->setAllowedTypes('error_message', 'string');
        $resolver->setAllowedTypes('allowed_urls', 'string[]');
        $resolver->setAllowedTypes('blocked_urls', 'string[]');
        $resolver->setAllowedTypes('xss_filter', 'bool');
        $resolver->setAllowedTypes('http_client', 'array');
        $resolver->setAllowedTypes('extractor', 'array');

        $resolver->setNormalizer('content_type_exc', function (Options $options, $value) {
            $resolver = new OptionsResolver();
            $resolver->setRequired(['action', 'name']);
            $resolver->setAllowedValues('action', ['link', 'exclude']);

            foreach ($value as $mime => $info) {
                $resolver->resolve($info);
            }

            return $value;
        });

        $config = $resolver->resolve($config);

        foreach ($config as $key => $value) {
            $this->$key = $value;
        }
    }

    public function getDebug(): bool
    {
        return $this->debug;
    }

    /**
     * @return 'info'|'debug'
     */
    public function getLogLevel(): string
    {
        return $this->log_level;
    }

    public function getRewriteRelativeUrls(): bool
    {
        return $this->rewrite_relative_urls;
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
        return $this->error_message;
    }

    public function getErrorMessageTitle(): string
    {
        return $this->error_message_title;
    }

    /**
     * @return array<string>
     */
    public function getAllowedUrls(): array
    {
        return $this->allowed_urls;
    }

    /**
     * @return array<string>
     */
    public function getBlockedUrls(): array
    {
        return $this->blocked_urls;
    }

    public function getXssFilter(): bool
    {
        return $this->xss_filter;
    }

    /**
     * @return array<string, array{name: string, action: 'link'|'exclude'}>
     */
    public function getContentTypeExc(): array
    {
        return $this->content_type_exc;
    }

    /**
     * @return 'preserve'|'footnotes'|'remove'
     */
    public function getContentLinks(): string
    {
        return $this->content_links;
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
        return $this->http_client;
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
