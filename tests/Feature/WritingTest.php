<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Vortech\Localize\Exceptions\InvalidTranslationPayload;
use Vortech\Localize\Exceptions\UnknownTranslationAttribute;
use Vortech\Localize\Exceptions\UnsavedTranslationOwner;
use Vortech\Localize\LocaleView;
use Vortech\Localize\Tests\Fixtures\Product;

beforeEach(function (): void {
    app()->setLocale('hu');
});

function rows(): array
{
    return DB::table('product_translations')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
}

it('creates a missing locale row with update and returns the same view', function (): void {
    $view = product()->locale('en');

    expect($view->update(['name' => 'Black T-shirt', 'slug' => 'black-t-shirt']))->toBe($view)
        ->and(rows())->toHaveCount(1)
        ->and(rows()[0])->toMatchArray(['locale' => 'en', 'name' => 'Black T-shirt', 'slug' => 'black-t-shirt', 'description' => null]);
});

it('updates only the submitted fields of an existing row', function (): void {
    $product = product();
    $product->locale('en')->update(['name' => 'Black T-shirt', 'description' => 'Cotton']);
    $product->locale('en')->update(['name' => 'Black tee']);

    expect(rows())->toHaveCount(1)
        ->and(rows()[0])->toMatchArray(['name' => 'Black tee', 'description' => 'Cotton']);
});

it('clears a field with an explicit null, which brings the fallback back', function (): void {
    $product = product()->saveLocales([
        'hu' => ['description' => 'Pamut póló'],
        'en' => ['description' => 'Cotton'],
    ]);

    $product->locale('en')->update(['description' => null]);

    expect($product->locale('en', fallback: false)->description)->toBeNull()
        ->and($product->locale('en')->description)->toBe('Pamut póló');
});

it('stores an empty string as an empty string', function (): void {
    $product = product();
    $product->locale('en')->update(['name' => '']);

    expect(rows()[0]['name'])->toBe('');
});

it('writes to the requested locale even with fallback disabled or enabled', function (): void {
    $product = product();
    $product->locale('en', fallback: false)->update(['name' => 'A']);
    $product->locale('de')->update(['name' => 'B']);

    expect(array_column(rows(), 'locale'))->toBe(['en', 'de']);
});

it('treats an empty payload as a no-op', function (): void {
    $product = product();

    $product->locale('en')->update([]);
    $product->saveLocales([]);
    $product->saveLocales(['en' => [], 'hu' => []]);

    expect(rows())->toBe([]);

    $product->locale('en')->update(['name' => 'A']);
    $before = rows();
    $this->travel(1)->minutes();
    $product->locale('en')->update([]);

    expect(rows())->toBe($before);
});

it('does not write a fallback value into the target locale', function (): void {
    $product = product()->saveLocales(['hu' => ['name' => 'Fekete póló']]);

    $product->locale('en')->update(['description' => 'Cotton']);

    expect(DB::table('product_translations')->where('locale', 'en')->value('name'))->toBeNull();
});

it('saves several locales in one call and leaves the rest alone', function (): void {
    $product = product()->saveLocales([
        'hu' => ['name' => 'Fekete póló', 'slug' => 'fekete-polo', 'description' => 'Prémium'],
        'en' => ['name' => 'Black T-shirt', 'slug' => 'black-t-shirt'],
        'de' => ['name' => 'Schwarzes T-Shirt'],
    ]);

    $product->saveLocales([
        'hu' => ['name' => 'Új terméknév'],
        'en' => ['slug' => null],
    ]);

    expect(rows())->toHaveCount(3)
        ->and(rows()[0])->toMatchArray(['name' => 'Új terméknév', 'slug' => 'fekete-polo', 'description' => 'Prémium'])
        ->and(rows()[1])->toMatchArray(['name' => 'Black T-shirt', 'slug' => null])
        ->and(rows()[2])->toMatchArray(['name' => 'Schwarzes T-Shirt']);
});

it('does not null fields that only some locales submit', function (): void {
    $product = product()->saveLocales([
        'hu' => ['name' => 'A', 'description' => 'D'],
        'en' => ['name' => 'B', 'description' => 'E'],
    ]);

    $product->saveLocales([
        'hu' => ['name' => 'A2'],
        'en' => ['description' => 'E2'],
        'de' => ['name' => 'C'],
        'fr' => ['description' => 'F'],
    ]);

    expect(rows()[0])->toMatchArray(['name' => 'A2', 'description' => 'D'])
        ->and(rows()[1])->toMatchArray(['name' => 'B', 'description' => 'E2'])
        ->and(rows()[2])->toMatchArray(['name' => 'C', 'description' => null])
        ->and(rows()[3])->toMatchArray(['name' => null, 'description' => 'F']);
});

it('keeps created_at on update and moves updated_at', function (): void {
    $product = product();
    $this->travelTo(now()->startOfSecond());
    $product->locale('en')->update(['name' => 'A']);
    $created = rows()[0]['created_at'];

    $this->travel(5)->minutes();
    $product->locale('en')->update(['name' => 'B']);

    expect(rows()[0]['created_at'])->toBe($created)
        ->and(rows()[0]['updated_at'])->not->toBe($created);
});

it('returns the owning model from saveLocales', function (): void {
    $product = product();

    expect($product->saveLocales(['en' => ['name' => 'A']]))->toBe($product);
});

it('rejects an unknown field and writes nothing', function (): void {
    $product = product();

    expect(fn (): Product => $product->saveLocales([
        'hu' => ['name' => 'A'],
        'en' => ['name' => 'B', 'price' => '10'],
    ]))->toThrow(UnknownTranslationAttribute::class)
        ->and(rows())->toBe([]);

    expect(fn (): LocaleView => $product->locale('en')->update(['sku' => 'x']))->toThrow(UnknownTranslationAttribute::class);
});

it('rejects a malformed payload before any write', function (mixed $payload): void {
    $product = product();

    expect(fn (): Product => $product->saveLocales(['hu' => ['name' => 'A'], ...$payload]))
        ->toThrow(InvalidTranslationPayload::class)
        ->and(rows())->toBe([]);
})->with([
    'locale is not an array' => [['en' => 'Black']],
    'value is an integer' => [['en' => ['name' => 12]]],
    'value is an array' => [['en' => ['name' => ['x']]]],
    'value is a bool' => [['en' => ['name' => true]]],
    'empty locale' => [['' => ['name' => 'x']]],
    'locale too long' => [[str_repeat('x', 36) => ['name' => 'x']]],
    'list instead of locale keys' => [[['name' => 'x']]],
]);

it('rolls back every locale when the database fails', function (): void {
    $product = product();
    $other = product('TEE-002')->saveLocales(['en' => ['slug' => 'taken']]);

    expect(fn (): Product => $product->saveLocales([
        'hu' => ['name' => 'Fekete póló'],
        'en' => ['slug' => 'taken'],
    ]))->toThrow(QueryException::class)
        ->and(DB::table('product_translations')->where('product_id', $product->getKey())->count())->toBe(0)
        ->and($other->refresh()->locale('en')->slug)->toBe('taken');
});

it('never rewrites another owner\'s row on a slug clash', function (): void {
    $first = product('A')->saveLocales(['en' => ['slug' => 'black', 'name' => 'First']]);
    $second = product('B');

    expect(fn (): LocaleView => $second->locale('en')->update(['slug' => 'black', 'name' => 'Second']))
        ->toThrow(QueryException::class);

    expect(DB::table('product_translations')->where('product_id', $first->getKey())->first())
        ->name->toBe('First')
        ->slug->toBe('black')
        ->and(DB::table('product_translations')->count())->toBe(1);
});

it('refuses to write for an unsaved model', function (): void {
    expect(fn (): Product => new Product()->saveLocales(['en' => ['name' => 'A']]))
        ->toThrow(UnsavedTranslationOwner::class)
        ->and(fn (): LocaleView => new Product()->locale('en')->update(['name' => 'A']))
        ->toThrow(UnsavedTranslationOwner::class);
});

it('does not save the pending changes of the owning model', function (): void {
    $product = product();
    $product->sku = 'CHANGED';

    $product->saveLocales(['en' => ['name' => 'A']]);

    expect($product->isDirty('sku'))->toBeTrue()
        ->and(DB::table('products')->value('sku'))->toBe('TEE-001');
});

it('works inside a caller transaction together with the model creation', function (): void {
    $product = DB::transaction(fn (): Product => Product::query()->create(['sku' => 'NEW'])->saveLocales([
        'hu' => ['name' => 'Új'],
        'en' => ['name' => 'New'],
    ]));

    expect($product->locale('en')->name)->toBe('New');

    expect(fn () => DB::transaction(function (): void {
        Product::query()->create(['sku' => 'LOST'])->saveLocales(['en' => ['name' => 'Lost']]);

        throw new RuntimeException('abort');
    }))->toThrow(RuntimeException::class)
        ->and(Product::query()->where('sku', 'LOST')->exists())->toBeFalse()
        ->and(DB::table('product_translations')->count())->toBe(2);
});

it('accepts locales like en-GB and pt_BR as they are', function (): void {
    $product = product()->saveLocales(['en-GB' => ['name' => 'Colour'], 'pt_BR' => ['name' => 'Cor']]);

    expect($product->locale('en-GB', fallback: false)->name)->toBe('Colour')
        ->and($product->locale('pt_BR', fallback: false)->name)->toBe('Cor')
        ->and($product->locale('en', fallback: false)->name)->toBeNull();
});
