<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Vortech\Localize\Tests\Fixtures\Product;

beforeEach(function (): void {
    app()->setLocale('hu');

    product('A')->saveLocales(['en' => ['slug' => 'black-t-shirt', 'name' => 'Black'], 'hu' => ['slug' => 'fekete-polo', 'name' => 'Fekete']]);
    product('B')->saveLocales(['en' => ['slug' => 'white-t-shirt', 'name' => 'White'], 'de' => ['name' => 'Weiss']]);
    product('C');
});

it('finds models by a column of one locale', function (): void {
    $found = Product::query()->whereLocale('en', fn (Builder $query) => $query->where('slug', 'black-t-shirt'))->get();

    expect($found->pluck('sku')->all())->toBe(['A']);
});

it('does not match the same value in another locale', function (): void {
    expect(Product::query()->whereLocale('hu', fn (Builder $query) => $query->where('slug', 'black-t-shirt'))->exists())->toBeFalse();
});

it('finds models that have a row in the locale without a callback', function (): void {
    expect(Product::query()->whereLocale('de')->pluck('sku')->all())->toBe(['B'])
        ->and(Product::query()->whereLocale('fr')->exists())->toBeFalse();
});

it('does not use the display fallback in a query', function (): void {
    expect(Product::query()->whereLocale('de', fn (Builder $query) => $query->where('name', 'Fekete'))->exists())->toBeFalse();
});

it('combines with other constraints and the model scope can be chained', function (): void {
    $found = Product::query()
        ->where('sku', 'B')
        ->whereLocale('en', fn (Builder $query) => $query->where('name', 'like', 'W%'))
        ->withLocales()
        ->firstOrFail();

    expect($found->locale('en')->name)->toBe('White');
});

it('eager loads every locale with withLocales()', function (): void {
    $products = Product::query()->withLocales()->get();

    DB::enableQueryLog();
    $names = $products->map(fn (Product $product): ?string => $product->locale('en')->name)->all();

    expect($names)->toBe(['Black', 'White', null])
        ->and(DB::getQueryLog())->toBe([])
        ->and($products->first()->locales)->toHaveCount(2);
});

it('loads only the wanted locales and the app locale', function (): void {
    $products = Product::query()->withLocales(['en'])->orderBy('sku')->get();

    expect($products[0]->locales->pluck('locale')->sort()->values()->all())->toBe(['en', 'hu'])
        ->and($products[1]->locales->pluck('locale')->all())->toBe(['en'])
        ->and($products[0]->locale('en')->name)->toBe('Black')
        ->and($products[1]->locale('de', fallback: false)->name)->toBeNull();
});

it('accepts a single locale as a string', function (): void {
    $product = Product::query()->withLocales('de')->where('sku', 'B')->firstOrFail();

    expect($product->locales->pluck('locale')->all())->toBe(['de'])
        ->and($product->locale('de')->name)->toBe('Weiss');
});
