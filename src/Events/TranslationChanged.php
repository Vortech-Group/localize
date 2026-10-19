<?php

declare(strict_types=1);

namespace Vortech\Localize\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * A translated column of a model changed value in a locale.
 *
 * Dispatched once per changed column after the write is committed. A column that
 * is saved with the value it already has does not dispatch.
 */
final readonly class TranslationChanged
{
    public function __construct(
        public Model $model,
        public string $key,
        public string $locale,
        public ?string $oldValue,
        public ?string $newValue,
    ) {}
}
