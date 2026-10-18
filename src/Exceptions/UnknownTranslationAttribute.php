<?php

declare(strict_types=1);

namespace Vortech\Localize\Exceptions;

/**
 * A column that the model did not declare as translatable was read or written.
 */
final class UnknownTranslationAttribute extends LocalizeException
{
    /**
     * @param  class-string  $class
     */
    public static function for(string $class, mixed $field): self
    {
        return new self(sprintf('"%s" is not a translatable column of %s.', is_string($field) ? $field : get_debug_type($field), $class));
    }
}
