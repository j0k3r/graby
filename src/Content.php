<?php

declare(strict_types=1);

namespace Graby;

use Graby\HttpClient\EffectiveResponse;

/**
 * Immutable container for Graby result.
 */
final class Content
{
    private EffectiveResponse $effectiveResponse;

    private string $html;

    private string $title;

    private ?string $language;

    private ?string $date;

    /** @var string[] */
    private array $authors;

    private ?string $image;

    private bool $isNativeAd;

    private ?string $summary;

    /**
     * @param string[] $authors
     */
    public function __construct(
        EffectiveResponse $effectiveResponse,
        string $html,
        string $title,
        ?string $language,
        ?string $date,
        array $authors,
        ?string $image,
        bool $isNativeAd,
        ?string $summary = null
    ) {
        $this->effectiveResponse = $effectiveResponse;
        $this->html = $html;
        $this->title = $title;
        $this->language = $language;
        $this->date = $date;
        $this->authors = $authors;
        $this->image = $image;
        $this->isNativeAd = $isNativeAd;
        $this->summary = $summary;
    }

    public function getEffectiveResponse(): EffectiveResponse
    {
        return $this->effectiveResponse;
    }

    public function getHtml(): string
    {
        return $this->html;
    }

    public function withHtml(string $html): self
    {
        $new = clone $this;
        $new->html = $html;

        return $new;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function withTitle(string $title): self
    {
        $new = clone $this;
        $new->title = $title;

        return $new;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function getDate(): ?string
    {
        return $this->date;
    }

    public function withDate(?string $date): self
    {
        $new = clone $this;
        $new->date = $date;

        return $new;
    }

    /**
     * @return string[]
     */
    public function getAuthors(): array
    {
        return $this->authors;
    }

    /**
     * @param string[] $authors
     */
    public function withAuthors(array $authors): self
    {
        $new = clone $this;
        $new->authors = $authors;

        return $new;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function getIsNativeAd(): bool
    {
        return $this->isNativeAd;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function withSummary(?string $summary): self
    {
        $new = clone $this;
        $new->summary = $summary;

        return $new;
    }
}
