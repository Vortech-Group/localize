<?php

declare(strict_types=1);

namespace Vortech\Localize\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Translation\PotentiallyTranslatedString;
use Vortech\Localize\Exceptions\InvalidTranslationDefinition;
use Vortech\Localize\Exceptions\UnknownTranslationAttribute;
use Vortech\Localize\TranslationMetadata;

/**
 * Checks that a translated value is not taken yet in its locale.
 *
 * It looks at stored rows of the target locale only, with no fallback, and
 * does not make the field required. It does not replace the unique index.
 */
final class UniqueTranslation implements ValidationRule
{
    private ?string $locale = null;

    private ?string $localePattern = null;

    private ?Model $ignored = null;

    /** @var list<array{string, string, mixed}> */
    private array $constraints = [];

    private function __construct(
        private readonly Model $model,
        private readonly TranslationMetadata $metadata,
        private readonly string $column,
        private readonly string $defaultLocale,
    ) {}

    /**
     * @param  class-string<Model>|Model  $model
     */
    public static function for(Model|string $model, string $column): self
    {
        $instance = $model instanceof Model ? $model : new $model;
        $metadata = TranslationMetadata::of($instance);

        if (! in_array($column, $metadata->fields, true)) {
            throw UnknownTranslationAttribute::for($instance::class, $column);
        }

        return new self($instance, $metadata, $column, app()->getLocale());
    }

    public function locale(string $locale): static
    {
        if ($this->localePattern !== null) {
            throw InvalidTranslationDefinition::invalid('locale() and localeFrom() cannot be combined.');
        }

        $this->locale = $locale;

        return $this;
    }

    /**
     * Reads the locale from the validated attribute's name: with
     * 'translations.*.slug', the attribute 'translations.en.slug' is checked in 'en'.
     */
    public function localeFrom(string $pattern): static
    {
        if ($this->locale !== null) {
            throw InvalidTranslationDefinition::invalid('locale() and localeFrom() cannot be combined.');
        }

        if (substr_count($pattern, '*') !== 1) {
            throw InvalidTranslationDefinition::invalid(sprintf('The localeFrom() pattern "%s" must contain exactly one "*".', $pattern));
        }

        $this->localePattern = $pattern;

        return $this;
    }

    /**
     * Leaves out the translations of an already saved model, for updates.
     */
    public function ignore(Model $owner): static
    {
        $metadata = TranslationMetadata::of($owner);

        if (! $owner instanceof $this->model
            || ! $owner->exists
            || $metadata->table !== $this->metadata->table
            || $metadata->foreignKey !== $this->metadata->foreignKey
            || $owner->getConnection()->getName() !== $this->model->getConnection()->getName()) {
            throw InvalidTranslationDefinition::invalid(sprintf(
                'ignore() needs a saved %s that uses the same translation table and connection.',
                $this->model::class,
            ));
        }

        $this->ignored = $owner;

        return $this;
    }

    public function where(string $column, mixed $value): static
    {
        return $this->constrain('=', $column, $value);
    }

    public function whereNot(string $column, mixed $value): static
    {
        return $this->constrain('!=', $column, $value);
    }

    public function whereNull(string $column): static
    {
        return $this->constrain('null', $column, null);
    }

    public function whereNotNull(string $column): static
    {
        return $this->constrain('not null', $column, null);
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $locale = $this->resolveLocale($attribute);

        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail('validation.string')->translate();

            return;
        }

        $query = $this->model->getConnection()
            ->table($this->metadata->table)
            ->where('locale', $locale)
            ->where($this->column, $value);

        if ($this->ignored instanceof Model) {
            $query->where($this->metadata->foreignKey, '!=', $this->ignored->getAttribute($this->metadata->ownerKey));
        }

        foreach ($this->constraints as [$operator, $column, $constraint]) {
            match ($operator) {
                'null' => $query->whereNull($column),
                'not null' => $query->whereNotNull($column),
                default => $query->where($column, $operator, $constraint),
            };
        }

        if ($query->exists()) {
            $fail('validation.unique')->translate();
        }
    }

    private function constrain(string $operator, string $column, mixed $value): static
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $column) !== 1) {
            throw InvalidTranslationDefinition::invalidColumn($column);
        }

        $this->constraints[] = [$operator, $column, $value];

        return $this;
    }

    private function resolveLocale(string $attribute): string
    {
        if ($this->localePattern === null) {
            return $this->locale ?? $this->defaultLocale;
        }

        $regex = '/\A'.str_replace('\*', '([^.]+)', preg_quote($this->localePattern, '/')).'\z/';

        if (preg_match($regex, $attribute, $matches) !== 1) {
            throw InvalidTranslationDefinition::invalid(sprintf(
                'The attribute "%s" does not match the localeFrom() pattern "%s".',
                $attribute,
                $this->localePattern,
            ));
        }

        return $matches[1];
    }
}
