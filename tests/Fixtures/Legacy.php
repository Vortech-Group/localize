<?php

declare(strict_types=1);

namespace Vortech\Localize\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Vortech\Localize\Attributes\Localized;
use Vortech\Localize\HasLocales;

#[Localized('label')]
class Legacy extends Model
{
    use HasLocales;

    protected $table = 'legacy_items';

    protected $guarded = [];

    public function getTranslationTable(): string
    {
        return 'legacy_labels';
    }

    public function getTranslationForeignKey(): string
    {
        return 'item_id';
    }
}
