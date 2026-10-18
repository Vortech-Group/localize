<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Vortech\Localize\Exceptions\InvalidTranslationDefinition;
use Vortech\Localize\Http\Middleware\SetLocale;
use Vortech\Localize\Tests\Fixtures\Product;

beforeEach(function (): void {
    config()->set('localize.locales', ['hu', 'en', 'pt_BR', 'en-GB']);
    app()->setLocale('hu');
    URL::defaults([SetLocale::PARAMETER => null]);

    Route::bind('product', fn (string $sku): Product => Product::query()->where('sku', $sku)->firstOrFail());

    Route::middleware(SubstituteBindings::class)->group(function (): void {
        Route::localized(function (): void {
            Route::get('products/{product}', fn (Product $product): array => [
                'locale' => app()->getLocale(),
                'name' => $product->locale()->name,
                'self' => route('products.show', $product),
            ])->name('products.show');

            Route::get('args/{first}', fn (string $first): string => $first)->name('args');
        });
    });

    Route::getRoutes()->refreshNameLookups();
});

it('sets the app locale from the URL prefix', function (): void {
    product('TEE')->saveLocales(['en' => ['name' => 'Tee'], 'hu' => ['name' => 'Póló']]);

    $this->getJson('/en/products/TEE')->assertOk()->assertJsonPath('locale', 'en')->assertJsonPath('name', 'Tee');
    $this->getJson('/hu/products/TEE')->assertOk()->assertJsonPath('locale', 'hu')->assertJsonPath('name', 'Póló');
});

it('answers 404 for a locale that is not configured', function (string $segment): void {
    product('TEE');

    $this->getJson("/$segment/products/TEE")->assertNotFound();
})->with(['de', 'EN', 'en_GB', 'products']);

it('supports locales like en-GB and pt_BR', function (): void {
    product('TEE');

    $this->getJson('/en-GB/products/TEE')->assertOk()->assertJsonPath('locale', 'en-GB');
    $this->getJson('/pt_BR/products/TEE')->assertOk()->assertJsonPath('locale', 'pt_BR');
});

it('keeps the locale in generated URLs without passing it', function (): void {
    $key = product('TEE')->getKey();

    $this->getJson('/en/products/TEE')->assertJsonPath('self', url("/en/products/$key"));
    $this->getJson('/hu/products/TEE')->assertJsonPath('self', url("/hu/products/$key"));
});

it('does not hand the locale to the controller', function (): void {
    $this->get('/en/args/value')->assertOk()->assertSee('value');
});

it('sets the locale before route model binding runs', function (): void {
    $seen = null;
    Route::bind('item', function (string $value) use (&$seen): string {
        $seen = app()->getLocale();

        return $value;
    });
    Route::middleware(SubstituteBindings::class)->group(function (): void {
        Route::localized(function (): void {
            Route::get('items/{item}', fn (string $item): string => $item);
        });
    });

    $this->get('/en/items/x')->assertOk();

    expect($seen)->toBe('en');
});

it('accepts a locale list for one group', function (): void {
    Route::localized(function (): void {
        Route::get('only', fn (): string => app()->getLocale());
    }, ['hu']);

    $this->get('/hu/only')->assertOk();
    $this->get('/en/only')->assertNotFound();
});

it('refuses to register localized routes without locales', function (): void {
    config()->set('localize.locales', []);

    expect(fn () => Route::localized(fn (): null => null))->toThrow(InvalidTranslationDefinition::class);
});

it('fails loudly when the middleware is used without a locale parameter', function (): void {
    Route::middleware(SetLocale::class)->get('bare', fn (): string => 'x');

    $this->withoutExceptionHandling()->get('/bare');
})->throws(InvalidTranslationDefinition::class);
