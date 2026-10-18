<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\DB;
use Vortech\Localize\Tests\Fixtures\Article;
use Vortech\Localize\Tests\Fixtures\Legacy;
use Vortech\Localize\Tests\Fixtures\Product;

beforeEach(function (): void {
    app()->setLocale('hu');
});

it('reads many models after eager loading without N+1 queries', function (): void {
    foreach (range(1, 5) as $i) {
        product("SKU-$i")->saveLocales([
            'hu' => ['name' => "Termék $i"],
            'en' => ['name' => "Product $i"],
        ]);
    }

    $products = Product::query()->with('locales')->get();

    DB::enableQueryLog();
    $names = $products->map(fn (Product $product): ?string => $product->locale('en')->name)->all();

    expect($names)->toBe(['Product 1', 'Product 2', 'Product 3', 'Product 4', 'Product 5'])
        ->and(DB::getQueryLog())->toBe([]);
});

it('reads from a restricted eager load that contains the needed locales', function (): void {
    product()->saveLocales([
        'hu' => ['name' => 'Fekete póló'],
        'en' => ['name' => 'Black T-shirt'],
        'de' => ['name' => 'Schwarz'],
    ]);

    $product = Product::query()
        ->with(['locales' => fn ($query) => $query->whereIn('locale', ['en', 'hu'])])
        ->firstOrFail();

    expect($product->locales)->toHaveCount(2)
        ->and($product->locale('en')->name)->toBe('Black T-shirt')
        ->and($product->locale('de')->name)->toBe('Fekete póló');
});

it('respects the ban on lazy loading', function (): void {
    product('A')->saveLocales(['hu' => ['name' => 'A']]);
    product('B');
    Model::preventLazyLoading();

    try {
        $products = Product::query()->get();

        expect(fn () => $products->first()->locale()->name)->toThrow(LazyLoadingViolationException::class);
        expect(Product::query()->with('locales')->get()->first()->locale()->name)->toBe('A');
    } finally {
        Model::preventLazyLoading(false);
    }
});

it('sees fresh values after a save and after refresh()', function (): void {
    $product = product()->saveLocales(['en' => ['name' => 'Old']]);
    $view = $product->locale('en');

    expect($view->name)->toBe('Old');

    $product->locale('en')->update(['name' => 'New']);
    expect($view->name)->toBe('New');

    DB::table('product_translations')->update(['name' => 'Elsewhere']);
    expect($view->name)->toBe('New');

    $product->refresh();
    expect($view->name)->toBe('Elsewhere');
});

it('does not load the relation just because a save happened', function (): void {
    $product = product()->saveLocales(['en' => ['name' => 'A']]);

    expect($product->relationLoaded('locales'))->toBeFalse();
});

it('serializes the declared fields in order, with fallback if asked', function (): void {
    $product = product()->saveLocales([
        'hu' => ['name' => 'Fekete póló', 'description' => 'Pamut póló'],
        'en' => ['name' => 'Black T-shirt', 'slug' => 'black-t-shirt'],
    ]);

    expect($product->locale('en')->toArray())->toBe([
        'slug' => 'black-t-shirt',
        'name' => 'Black T-shirt',
        'description' => 'Pamut póló',
    ])
        ->and($product->locale('en', fallback: false)->toArray())->toBe([
            'slug' => 'black-t-shirt',
            'name' => 'Black T-shirt',
            'description' => null,
        ])
        ->and(json_encode($product->locale('en', fallback: false)))->toBe('{"slug":"black-t-shirt","name":"Black T-shirt","description":null}');
});

it('renders the view as JSON with toJson()', function (): void {
    $product = product()->saveLocales(['en' => ['name' => 'Black T-shirt', 'slug' => 'black-t-shirt']]);

    expect($product->locale('en', fallback: false)->toJson())->toBe('{"slug":"black-t-shirt","name":"Black T-shirt","description":null}')
        ->and($product->locale('en', fallback: false)->toJson(JSON_PRETTY_PRINT))->toContain("\n    \"slug\"");
});

it('leaves the toArray() and attributes of the owning model alone', function (): void {
    $product = product()->saveLocales(['hu' => ['name' => 'A']])->load('locales');

    expect(array_keys($product->toArray()))->toBe(['sku', 'updated_at', 'created_at', 'id', 'locales'])
        ->and($product->name)->toBeNull();
});

it('deletes translations with the owner and keeps them on soft delete', function (): void {
    $product = product()->saveLocales(['hu' => ['name' => 'A']]);
    $article = Article::query()->create()->saveLocales(['hu' => ['title' => 'Cikk']]);

    $article->delete();
    expect(DB::table('article_translations')->count())->toBe(1);

    $article->restore();
    expect($article->fresh()->locale()->title)->toBe('Cikk');

    $product->delete();
    expect(DB::table('product_translations')->count())->toBe(0);
});

it('works with a ULID owner key', function (): void {
    $article = Article::query()->create()->saveLocales([
        'hu' => ['title' => 'Cikk', 'slug' => 'cikk'],
        'en' => ['title' => 'Article'],
    ]);

    expect($article->fresh()->locale('en')->title)->toBe('Article')
        ->and(DB::table('article_translations')->value('article_id'))->toBe($article->getKey());
});

it('honours getTranslationTable() and getTranslationForeignKey() overrides', function (): void {
    $legacy = Legacy::query()->create()->saveLocales(['hu' => ['label' => 'Címke']]);

    expect($legacy->locale()->label)->toBe('Címke')
        ->and(DB::table('legacy_labels')->value('item_id'))->toBe($legacy->getKey());
});

it('supports the documented query through the locales relation', function (): void {
    product('A')->saveLocales(['en' => ['slug' => 'black-t-shirt', 'name' => 'Black']]);
    product('B')->saveLocales(['en' => ['slug' => 'white-t-shirt', 'name' => 'White']]);

    $found = Product::query()
        ->whereHas('locales', function ($query): void {
            $query->where('locale', 'en')->where('slug', 'black-t-shirt');
        })
        ->with('locales')
        ->firstOrFail();

    expect($found->sku)->toBe('A')
        ->and($found->locale('en')->name)->toBe('Black');
});

it('uses the owner connection for the writes', function (): void {
    config()->set('database.connections.other', config('database.connections.testing'));
    $product = product();
    $product->setConnection('other');

    expect($product->locales()->getRelated()->getConnectionName())->toBe('other');
});
