<?php

declare(strict_types=1);

namespace Vortech\Localize\Exceptions;

/**
 * Translations can only be written for a model that has been saved.
 */
final class UnsavedTranslationOwner extends LocalizeException
{
    /**
     * @param  class-string  $class
     */
    public static function for(string $class): self
    {
        return new self(sprintf('Cannot write translations: this %s has not been saved yet.', $class));
    }
}
