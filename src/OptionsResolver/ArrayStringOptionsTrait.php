<?php

declare(strict_types=1);

namespace Graby\OptionsResolver;

use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;

/**
 * To be used inside a `OptionsResolver->setNormalizer` to enforce that a given value is an array on string, string.
 */
trait ArrayStringOptionsTrait
{
    public function validateArray(array $array, string $option, string $key = null): void
    {
        if (null === $key) {
            foreach ($array as $arrayKey => $arrayValue) {
                if (!\is_string($arrayKey)) {
                    throw new InvalidOptionsException(sprintf('The option "%s" with key "%s" is expected to be of type "string", but is of type "%s".', $option, $arrayKey, get_debug_type($arrayKey)));
                }
                if (!\is_string($arrayValue)) {
                    throw new InvalidOptionsException(sprintf('The option "%s" with value "%s" is expected to be of type "string", but is of type "%s".', $option, $arrayValue, get_debug_type($arrayValue)));
                }
            }
        } elseif (!empty($array[$key])) {
            foreach ($array[$key] as $arrayKey => $arrayValue) {
                if (!\is_string($arrayKey)) {
                    throw new InvalidOptionsException(sprintf('The option "%s" with key "%s" is expected to be of type "string", but is of type "%s".', $option, $arrayKey, get_debug_type($arrayKey)));
                }
                if (!\is_string($arrayValue)) {
                    throw new InvalidOptionsException(sprintf('The option "%s" with value "%s" is expected to be of type "string", but is of type "%s".', $option, $arrayValue, get_debug_type($arrayValue)));
                }
            }
        }
    }
}
