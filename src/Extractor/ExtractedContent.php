<?php

declare(strict_types=1);

namespace Graby\Extractor;

use Graby\SiteConfig\SiteConfig;
use Readability\Readability;

/**
 * ExtractedContent.
 *
 * Contains the result of content extraction with all extracted data
 * and the Readability instance for potential reuse.
 */
final class ExtractedContent
{
    public function __construct(
        private ?Readability $readability = null,
        private ?SiteConfig $siteConfig = null,
        private ?string $title = null,
        private ?string $language = null,
        /** @var string[] */
        private array $authors = [],
        private ?\DOMElement $body = null,
        private ?string $image = null,
        private bool $nativeAd = false,
        private ?string $date = null,
        private bool $success = false,
        private ?string $nextPageUrl = null
    ) {
    }

    public function getReadability(): ?Readability
    {
        return $this->readability;
    }

    public function getContent(): ?\DOMElement
    {
        return $this->body;
    }

    public function isNativeAd(): bool
    {
        return $this->nativeAd;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getDate(): ?string
    {
        return $this->date;
    }

    /**
     * @return string[]
     */
    public function getAuthors(): array
    {
        return $this->authors;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function getSiteConfig(): ?SiteConfig
    {
        return $this->siteConfig;
    }

    public function getNextPageUrl(): ?string
    {
        return $this->nextPageUrl;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }
}
