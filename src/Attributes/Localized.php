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
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Localized
{
    /** @var list<string> */
    public array $fields;

    public bool $magic;

    /**
     * The column names are positional; `magic` is the only named argument.
     * (A named argument after a variadic list is collected with its name as the key.)
     */
    public function __construct(string|bool ...$arguments)
    {
        $magic = $arguments['magic'] ?? false;
        unset($arguments['magic']);

        if (! is_bool($magic)) {
            throw InvalidTranslationDefinition::invalid('The magic option of #[Localized] must be true or false.');
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
    }
}
