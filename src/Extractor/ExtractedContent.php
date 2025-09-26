<?php

declare(strict_types=1);

namespace Graby\Extractor;

use Graby\SiteConfig\SiteConfig;
use Readability\Readability;

/**
 * Contains the result of content extraction with all extracted data
 * and the Readability instance for potential reuse.
 */
final class ExtractedContent
{
    public function __construct(
        public ?Readability $readability = null,
        public ?SiteConfig $siteConfig = null,
        public ?string $title = null,
        public ?string $language = null,
        /** @var string[] */
        public array $authors = [],
        public ?\DOMElement $content = null,
        public ?string $image = null,
        public bool $isNativeAd = false,
        public ?string $date = null,
        public bool $isSuccess = false,
        public ?string $nextPageUrl = null
    ) {
    }
}
