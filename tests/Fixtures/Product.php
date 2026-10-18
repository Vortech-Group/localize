<?php

declare(strict_types=1);

namespace Vortech\Localize\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Vortech\Localize\Attributes\Localized;
use Vortech\Localize\HasLocales;

#[Localized('slug', 'name', 'description')]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use HasLocales;

    protected static string $factory = ProductFactory::class;

    protected $guarded = [];
}
