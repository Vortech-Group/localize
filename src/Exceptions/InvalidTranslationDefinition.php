<?php

declare(strict_types=1);

namespace Vortech\Localize\Exceptions;

/**
 * The model, the #[Localized] attribute or a rule is configured incorrectly.
 */
final class InvalidTranslationDefinition extends LocalizeException
{
    /**
     * @param  class-string  $class
     */
    public static function missingAttribute(string $class): self
    {
        return new self(sprintf('%s must declare its translatable columns with #[Localized(...)].', $class));
    }

    /**
     * @param  class-string  $class
     */
    public static function missingTrait(string $class): self
    {
        return new self(sprintf('%s must use the HasLocales trait.', $class));
    }

    /**
     * @param  class-string  $class
     */
    public static function noFields(string $class): self
    {
        return new self(sprintf('The #[Localized] attribute of %s lists no columns.', $class));
    }

    /**
     * @param  class-string  $class
     */
    public static function invalidField(string $class, string $field): self
    {
        return new self(sprintf('"%s" on %s is not a plain column name.', $field, $class));
    }

    /**
     * @param  class-string  $class
     */
    public static function reservedField(string $class, string $field): self
    {
        return new self(sprintf('"%s" on %s is an infrastructure column and cannot be translatable.', $field, $class));
    }

    /**
     * @param  class-string  $class
     */
    public static function invalidSlug(string $class, string $reason): self
    {
        return new self(sprintf('The #[LocalizedSlug] of %s is invalid: %s.', $class, $reason));
    }

    public static function invalidColumn(string $column): self
    {
        return new self(sprintf('"%s" is not a plain column name.', $column));
    }

    public static function invalid(string $message): self
    {
        return new self($message);
    }
}
