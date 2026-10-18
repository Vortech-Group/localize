<?php

declare(strict_types=1);

namespace Vortech\Localize\Exceptions;

/**
 * The translations passed to a write do not have the locale => column => value shape.
 */
final class InvalidTranslationPayload extends LocalizeException
{
    public static function invalidLocale(mixed $locale, int $maxLength): self
    {
        return new self(sprintf(
            'A locale must be a non-empty string of at most %d characters, %s given.',
            $maxLength,
            get_debug_type($locale),
        ));
    }

    public static function notAnArray(string $locale, mixed $value): self
    {
        return new self(sprintf('The translations of "%s" must be an array, %s given.', $locale, get_debug_type($value)));
    }

    public static function invalidValue(string $locale, string $field, mixed $value): self
    {
        return new self(sprintf('"%s.%s" must be a string or null, %s given.', $locale, $field, get_debug_type($value)));
    }
}
