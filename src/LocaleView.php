<?php

declare(strict_types=1);

namespace Vortech\Localize;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use LogicException;
use Vortech\Localize\Exceptions\UnknownTranslationAttribute;
use Vortech\Localize\Rules\UniqueTranslation;

/**
 * A model seen in one locale.
 *
 * The view keeps no copy of the data: every read goes to the model's loaded
 * `locales` relation, so a refresh or a save is visible immediately.
 *
 * @implements Arrayable<string, string|null>
 */
final readonly class LocaleView implements Arrayable, Jsonable, JsonSerializable
{
    public function __construct(
        private Model $owner,
        private string $locale,
        private string $appLocale,
        private bool $fallback,
    ) {}

    public function __get(string $name): ?string
    {
        return $this->value($name);
    }

    /**
     * The same as reading the column as a property, for a column name in a variable.
     */
    public function get(string $field): ?string
    {
        return $this->value($field);
    }

    public function __isset(string $name): bool
    {
        return in_array($name, $this->fields(), true) && $this->value($name) !== null;
    }

    public function __set(string $name, mixed $value): never
    {
        throw new LogicException('A locale view is read-only. Use update([...]) to save translations.');
    }

    public function __unset(string $name): never
    {
        throw new LogicException('A locale view is read-only. Use update([...]) to save translations.');
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Saves the given columns to this view's locale right away, and returns the view.
     *
     * @param  array<string, string|null>  $attributes
     */
    public function update(array $attributes): static
    {
        new TranslationWriter()->write($this->owner, [$this->locale => $attributes]);

        return $this;
    }

    /**
     * The unique rule for one of this view's columns, in this locale, ignoring
     * the model itself. The fallback setting does not matter to it.
     */
    public function unique(string $attribute): UniqueTranslation
    {
        $rule = UniqueTranslation::for($this->owner, $attribute)->locale($this->locale);

        return $this->owner->exists ? $rule->ignore($this->owner) : $rule;
    }

    /**
     * Every declared column in declaration order, resolved with this view's fallback.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        $values = [];

        foreach ($this->fields() as $field) {
            $values[$field] = $this->value($field);
        }

        return $values;
    }

    /**
     * @param  int  $options
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR | $options);
    }

    /**
     * @return array<string, string|null>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return list<string>
     */
    private function fields(): array
    {
        return TranslationMetadata::fields($this->owner::class);
    }

    private function value(string $field): ?string
    {
        if (! in_array($field, $this->fields(), true)) {
            throw UnknownTranslationAttribute::for($this->owner::class, $field);
        }

        if (! $this->owner->exists) {
            return null;
        }

        $rows = $this->owner->getRelationValue('locales');

        if (! $rows instanceof Collection) {
            return null;
        }

        $value = $this->stored($rows, $this->locale, $field);

        if ($value === null && $this->fallback && $this->appLocale !== $this->locale) {
            return $this->stored($rows, $this->appLocale, $field);
        }

        return $value;
    }

    /**
     * @param  Collection<array-key, Model>  $rows
     */
    private function stored(Collection $rows, string $locale, string $field): ?string
    {
        $row = $rows->first(static fn (Model $row): bool => $row instanceof TranslationRow && $row->locale === $locale);

        if (! $row instanceof TranslationRow) {
            return null;
        }

        $value = $row->getAttribute($field);

        return is_scalar($value) ? (string) $value : null;
    }
}
