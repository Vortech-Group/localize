<?php

declare(strict_types=1);

namespace Vortech\Localize\Attributes;

use Attribute;
use Vortech\Localize\Exceptions\InvalidTranslationDefinition;

/**
 * Declares the translatable columns of a model.
 *
 * The columns live on the model's translation table, which is created by the
 * application's own migration. With `magic: true`, `$model->name` reads the
 * column in the current app locale (what `$model->locale()->name` does) and
 * assigning it throws; `$model->locale('hu')->name` keeps working.
 *
 * `casts: ['features' => 'array']` stores a column as JSON: it is written from an
 * array and read back as one. Only `array` is supported.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Localized
{
    /** @var list<string> */
    public array $fields;

    public bool $magic;

    /** @var array<string, string> */
    public array $casts;

    /**
     * The column names are positional; `magic` and `casts` are the only named arguments.
     * (A named argument after a variadic list is collected with its name as the key.)
     *
     * @param  string|bool|array<array-key, mixed>  ...$arguments
     */
    public function __construct(string|bool|array ...$arguments)
    {
        $magic = $arguments['magic'] ?? false;
        $casts = $arguments['casts'] ?? [];
        unset($arguments['magic'], $arguments['casts']);

        if (! is_bool($magic)) {
            throw InvalidTranslationDefinition::invalid('The magic option of #[Localized] must be true or false.');
        }

        if (! is_array($casts)) {
            throw InvalidTranslationDefinition::invalid('The casts option of #[Localized] must be an array of column => cast.');
        }

        $validCasts = [];

        foreach ($casts as $column => $cast) {
            if (! is_string($column) || $cast !== 'array') {
                throw InvalidTranslationDefinition::invalid(sprintf('#[Localized] casts must be column => "array", "%s" given for "%s".', get_debug_type($cast), (string) $column));
            }

            $validCasts[$column] = $cast;
        }

        $fields = [];

        foreach ($arguments as $name => $field) {
            if (is_string($name) || ! is_string($field)) {
                throw InvalidTranslationDefinition::invalid(sprintf('#[Localized] takes column names and the magic option, not "%s".', is_string($name) ? $name : get_debug_type($field)));
            }

            $fields[] = $field;
        }

        $this->fields = $fields;
        $this->magic = $magic;
        $this->casts = $validCasts;
    }
}
