<?php

declare(strict_types=1);

namespace Vortech\Localize\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vortech\Localize\Attributes\Localized;
use Vortech\Localize\Attributes\LocalizedSlug;
use Vortech\Localize\HasLocales;

#[Localized('slug', 'title')]
#[LocalizedSlug(from: 'title')]
class Article extends Model
{
    use HasLocales;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = [];
}
