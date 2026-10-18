<?php

declare(strict_types=1);

use Vortech\Localize\Tests\Fixtures\Product;
use Vortech\Localize\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

function product(string $sku = 'TEE-001'): Product
{
    return Product::query()->create(['sku' => $sku]);
}
