<?php

namespace Graby;

use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Configuration for Graby as a Value Object.
 */
class GrabyConfig
{
    private bool $debug;
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
    private string $content_links;
    private array $http_client;
    private array $extractor;

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

    public function getContentTypeExc(): array
    {
        return $this->content_type_exc;
    }

    public function getContentLinks(): string
    {
        return $this->content_links;
    }

    public function getHttpClient(): array
    {
        return $this->http_client;
    }

    public function getExtractor(): array
    {
        return $this->extractor;
    }
}
