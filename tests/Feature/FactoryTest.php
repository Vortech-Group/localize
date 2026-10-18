<?php

declare(strict_types=1);

use Vortech\Localize\Tests\Fixtures\Product;

it('saves a full locale payload after creating', function (): void {
    $product = Product::factory()->withLocales([
        'hu' => ['name' => 'Fekete póló'],
        'en' => ['name' => 'Black T-shirt'],
    ])->create();

    expect($product->locale('hu', fallback: false)->name)->toBe('Fekete póló')
        ->and($product->locale('en', fallback: false)->name)->toBe('Black T-shirt');
});

it('gives the same values to every listed locale', function (): void {
    $product = Product::factory()->withLocales(['hu', 'en'], ['name' => 'Póló'])->create();

    expect($product->locale('hu', fallback: false)->name)->toBe('Póló')
        ->and($product->locale('en', fallback: false)->name)->toBe('Póló');
});

it('pairs a list of value sets with the locales', function (): void {
    $product = Product::factory()->withLocales(['hu', 'en'], [['name' => 'Póló'], ['name' => 'Tee']])->create();

    expect($product->locale('hu', fallback: false)->name)->toBe('Póló')
        ->and($product->locale('en', fallback: false)->name)->toBe('Tee');

    expect(fn () => Product::factory()->withLocales(['hu', 'en'], [['name' => 'x']]))->toThrow(InvalidArgumentException::class);
});

it('saves locales for every model of a batch', function (): void {
    $products = Product::factory()->count(3)->withLocales('en', ['name' => 'Tee'])->create();

    expect($products)->each(fn ($product) => $product->locale('en', fallback: false)->name->toBe('Tee'));
});

it('does not save anything when the factory only makes models', function (): void {
    $product = Product::factory()->withLocales('en', ['name' => 'Tee'])->make();

    expect($product->exists)->toBeFalse();
});
