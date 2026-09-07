<?php

declare(strict_types=1);

namespace Graby\Extractor;

use Graby\OptionsResolver\ArrayStringOptionsTrait;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Configuration for Readability as a Value Object.
 */
readonly class ReadabilityConfig
{
    use ArrayStringOptionsTrait;

    public function __construct(
        /** @var array<string, string> */
        public array $preFilters = [],
        /** @var array<string, string> */
        public array $postFilters = [],
    ) {
        $resolver = new OptionsResolver();
        $resolver->setDefined([
            'preFilters',
            'postFilters',
        ]);

        $resolver->setNormalizer('preFilters', function (Options $options, $value) {
            $this->validateArray($value, 'preFilters');

            return $value;
        });

        $resolver->setNormalizer('postFilters', function (Options $options, $value) {
            $this->validateArray($value, 'postFilters');

            return $value;
        });

        // Discarding result – just validating, nothing to normalize.
        $resolver->resolve([
            'preFilters' => $preFilters,
            'postFilters' => $postFilters,
        ]);
    }
}
