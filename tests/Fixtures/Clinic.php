<?php

declare(strict_types=1);

namespace Vortech\Localize\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Vortech\Localize\Attributes\Localized;
use Vortech\Localize\HasLocales;

#[Localized('name')]
class Clinic extends Model
{
    use HasLocales;

    protected $guarded = [];
}
