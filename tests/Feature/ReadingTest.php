<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vortech\Localize\Exceptions\UnknownTranslationAttribute;
use Vortech\Localize\LocaleView;
use Vortech\Localize\Tests\Fixtures\Clinic;
use Vortech\Localize\Tests\Fixtures\Product;

beforeEach(function (): void {
    app()->setLocale('hu');
});

it('uses the app locale when no locale is given', function (): void {
    $product = product()->saveLocales([
        'hu' => ['name' => 'Fekete póló'],
        'en' => ['name' => 'Black T-shirt'],
    ]);

    expect($product->locale())->toBeInstanceOf(LocaleView::class)
        ->and($product->locale()->name)->toBe('Fekete póló')
        ->and($product->locale('en')->name)->toBe('Black T-shirt');
});

it('falls back per field to the app locale, then null', function (): void {
    $product = product()->saveLocales([
        'hu' => ['name' => 'Fekete póló', 'description' => 'Pamut póló'],
        'en' => ['name' => 'Black T-shirt'],
    ]);

    expect($product->locale('en')->name)->toBe('Black T-shirt')
        ->and($product->locale('en')->description)->toBe('Pamut póló')
        ->and($product->locale('de')->name)->toBe('Fekete póló')
        ->and($product->locale('de')->slug)->toBeNull();
});

it('does not fall back to app.fallback_locale or to another existing locale', function (): void {
    config()->set('app.fallback_locale', 'en');
    $product = product()->saveLocales(['en' => ['name' => 'Black T-shirt'], 'de' => ['name' => 'Schwarz']]);

    expect($product->locale('fr')->name)->toBeNull()
        ->and($product->locale('en-GB')->name)->toBeNull();
});

it('reads only the requested locale without fallback', function (): void {
    $product = product()->saveLocales([
        'hu' => ['name' => 'Fekete póló', 'description' => 'Pamut póló'],
        'en' => ['name' => 'Black T-shirt'],
    ]);

    expect($product->locale('en', fallback: false)->description)->toBeNull()
        ->and($product->locale('de', fallback: false)->name)->toBeNull();
});

it('tells null, empty string and "0" apart', function (): void {
    $product = product()->saveLocales([
        'hu' => ['name' => 'Fekete póló', 'description' => 'Pamut póló', 'slug' => 'fekete'],
        'en' => ['name' => '', 'description' => '0', 'slug' => null],
    ]);

    $english = $product->locale('en');

    expect($english->name)->toBe('')
        ->and($english->description)->toBe('0')
        ->and($english->slug)->toBe('fekete');
});

it('pins the locale context when the view is created', function (): void {
    $product = product()->saveLocales([
        'hu' => ['name' => 'Fekete póló'],
        'en' => ['name' => 'Black T-shirt'],
    ]);

    $hungarian = $product->locale();
    app()->setLocale('en');
    $english = $product->locale();
    $germanAfterSwitch = $product->locale('de');

    expect($hungarian->name)->toBe('Fekete póló')
        ->and($english->name)->toBe('Black T-shirt')
        ->and($germanAfterSwitch->name)->toBe('Black T-shirt')
        ->and($hungarian->getLocale())->toBe('hu')
        ->and(app()->getLocale())->toBe('en');
});

it('throws for a field that was not declared', function (): void {
    expect(fn () => product()->locale()->price)->toThrow(UnknownTranslationAttribute::class);
});

it('keeps every model on its own translation table', function (): void {
    $product = product()->saveLocales(['hu' => ['name' => 'Póló']]);
    $clinic = Clinic::query()->create()->saveLocales(['hu' => ['name' => 'Rendelő']]);

    expect($product->locale()->name)->toBe('Póló')
        ->and($clinic->locale()->name)->toBe('Rendelő')
        ->and($clinic->locales()->getRelated()->getTable())->toBe('clinic_translations')
        ->and($product->locales()->getRelated()->getTable())->toBe('product_translations');
});

it('returns null for every field of an unsaved model without querying', function (): void {
    $product = new Product;

    DB::enableQueryLog();

    expect($product->locale('en')->name)->toBeNull()
        ->and($product->locale()->toArray())->toBe(['slug' => null, 'name' => null, 'description' => null])
        ->and(DB::getQueryLog())->toBe([]);
});

it('does not allow assigning fields on the view', function (): void {
    $view = product()->locale('en');

    expect(fn (): string => $view->name = 'x')->toThrow(LogicException::class);
});

it('supports isset on the view', function (): void {
    $product = product()->saveLocales(['en' => ['name' => 'Black T-shirt']]);

    expect(isset($product->locale('en')->name))->toBeTrue()
        ->and(isset($product->locale('en')->slug))->toBeFalse()
        ->and(isset($product->locale('en')->sku))->toBeFalse();
});
