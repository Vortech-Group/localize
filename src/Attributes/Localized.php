<?php

declare(strict_types=1);

namespace Vortech\Localize\Attributes;

use Attribute;

/**
 * Declares the translatable columns of a model.
 *
 * The columns live on the model's translation table, which is created by the
 * application's own migration.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Localized
{
    /** @var list<string> */
    public array $fields;

    public function __construct(string ...$fields)
    {
        $this->fields = array_values($fields);
    }
}
