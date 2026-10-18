<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Vortech\Localize\Exceptions\InvalidTranslationDefinition;
use Vortech\Localize\Exceptions\UnknownTranslationAttribute;
use Vortech\Localize\Rules\UniqueTranslation;
use Vortech\Localize\Tests\Fixtures\Article;
use Vortech\Localize\Tests\Fixtures\Clinic;
use Vortech\Localize\Tests\Fixtures\Plain;
use Vortech\Localize\Tests\Fixtures\Product;

beforeEach(function (): void {
    app()->setLocale('hu');
});

function passes(array $data, array $rules): bool
{
    return ! Validator::make($data, $rules)->fails();
}

it('fails for a taken value in the same locale only', function (): void {
    product()->saveLocales(['en' => ['slug' => 'black'], 'hu' => ['slug' => 'fekete']]);
    $rule = fn (string $locale): UniqueTranslation => UniqueTranslation::for(Product::class, 'slug')->locale($locale);

    expect(passes(['slug' => 'black'], ['slug' => $rule('en')]))->toBeFalse()
        ->and(passes(['slug' => 'black'], ['slug' => $rule('hu')]))->toBeTrue()
        ->and(passes(['slug' => 'other'], ['slug' => $rule('en')]))->toBeTrue();
});

it('uses the app locale of the moment the rule was created by default', function (): void {
    product()->saveLocales(['hu' => ['slug' => 'fekete']]);
    $rule = UniqueTranslation::for(Product::class, 'slug');

    app()->setLocale('en');

    expect(passes(['slug' => 'fekete'], ['slug' => $rule]))->toBeFalse();
});

it('does not use the fallback value to decide', function (): void {
    product()->saveLocales(['hu' => ['slug' => 'fekete']]);

    expect(passes(['slug' => 'fekete'], ['slug' => UniqueTranslation::for(Product::class, 'slug')->locale('en')]))->toBeTrue();
});

it('uses the validation.unique message on the field', function (): void {
    product()->saveLocales(['en' => ['slug' => 'black']]);

    $validator = Validator::make(['slug' => 'black'], ['slug' => UniqueTranslation::for(Product::class, 'slug')->locale('en')]);

    expect($validator->errors()->get('slug'))->toBe(['The slug has already been taken.']);
});

it('ignores the model itself on update, but not another one', function (): void {
    $mine = product('A')->saveLocales(['en' => ['slug' => 'black']]);
    $theirs = product('B')->saveLocales(['en' => ['slug' => 'white']]);
    $rule = fn (Product $owner, string $slug): bool => passes(['slug' => $slug], [
        'slug' => UniqueTranslation::for(Product::class, 'slug')->locale('en')->ignore($owner),
    ]);

    expect($rule($mine, 'black'))->toBeTrue()
        ->and($rule($mine, 'white'))->toBeFalse()
        ->and($rule($theirs, 'black'))->toBeFalse();
});

it('excludes on the owner foreign key of a ULID model', function (): void {
    $mine = Article::query()->create()->saveLocales(['en' => ['slug' => 'a']]);
    Article::query()->create()->saveLocales(['en' => ['slug' => 'b']]);

    $rule = fn (string $slug): bool => passes(['slug' => $slug], [
        'slug' => UniqueTranslation::for(Article::class, 'slug')->locale('en')->ignore($mine),
    ]);

    expect($rule('a'))->toBeTrue()->and($rule('b'))->toBeFalse();
});

it('is built by the view shorthand with the view locale and the owner excluded', function (): void {
    $mine = product('A')->saveLocales(['en' => ['slug' => 'black']]);
    product('B')->saveLocales(['en' => ['slug' => 'white']]);

    $check = fn (string $slug, bool $fallback = true): bool => passes(['slug' => $slug], [
        'slug' => $mine->locale('en', fallback: $fallback)->unique('slug'),
    ]);

    expect($check('black'))->toBeTrue()
        ->and($check('white'))->toBeFalse()
        ->and($check('white', fallback: false))->toBeFalse();
});

it('does not exclude anything for the shorthand of an unsaved model', function (): void {
    product()->saveLocales(['en' => ['slug' => 'black']]);

    expect(passes(['slug' => 'black'], ['slug' => (new Product)->locale('en')->unique('slug')]))->toBeFalse();
});

it('resolves the locale from a wildcard and reports the exact key', function (): void {
    product()->saveLocales(['en' => ['slug' => 'black'], 'hu' => ['slug' => 'fekete']]);

    $validator = Validator::make(
        ['translations' => ['en' => ['slug' => 'black'], 'hu' => ['slug' => 'black']]],
        ['translations.*.slug' => [UniqueTranslation::for(Product::class, 'slug')->localeFrom('translations.*.slug')]],
    );

    expect($validator->errors()->keys())->toBe(['translations.en.slug']);
});

it('can be reused across locales and requests without leaking state', function (): void {
    product()->saveLocales(['en' => ['slug' => 'taken-en'], 'hu' => ['slug' => 'taken-hu']]);
    $rule = UniqueTranslation::for(Product::class, 'slug')->localeFrom('translations.*.slug');

    $first = Validator::make(['translations' => ['en' => ['slug' => 'taken-hu'], 'hu' => ['slug' => 'taken-hu']]], ['translations.*.slug' => [$rule]]);
    $second = Validator::make(['translations' => ['hu' => ['slug' => 'taken-en']]], ['translations.*.slug' => [$rule]]);

    expect($first->errors()->keys())->toBe(['translations.hu.slug'])
        ->and($second->errors()->keys())->toBe([]);
});

it('supports en-GB and pt_BR in the wildcard', function (): void {
    product()->saveLocales(['en-GB' => ['slug' => 'colour'], 'pt_BR' => ['slug' => 'cor']]);

    $validator = Validator::make(
        ['translations' => ['en-GB' => ['slug' => 'colour'], 'pt_BR' => ['slug' => 'colour']]],
        ['translations.*.slug' => [UniqueTranslation::for(Product::class, 'slug')->localeFrom('translations.*.slug')]],
    );

    expect($validator->errors()->keys())->toBe(['translations.en-GB.slug']);
});

it('ignores the owner in the wildcard form too', function (): void {
    $product = product()->saveLocales(['en' => ['slug' => 'black']]);

    $validator = Validator::make(
        ['translations' => ['en' => ['slug' => 'black']]],
        ['translations.*.slug' => [UniqueTranslation::for(Product::class, 'slug')->localeFrom('translations.*.slug')->ignore($product)]],
    );

    expect($validator->passes())->toBeTrue();
});

it('rejects invalid configuration', function (Closure $build): void {
    expect($build)->toThrow(InvalidTranslationDefinition::class);
})->with([
    'two wildcards' => [fn (): UniqueTranslation => UniqueTranslation::for(Product::class, 'slug')->localeFrom('a.*.b.*')],
    'no wildcard' => [fn (): UniqueTranslation => UniqueTranslation::for(Product::class, 'slug')->localeFrom('translations.en.slug')],
    'locale then localeFrom' => [fn (): UniqueTranslation => UniqueTranslation::for(Product::class, 'slug')->locale('en')->localeFrom('t.*.slug')],
    'localeFrom then locale' => [fn (): UniqueTranslation => UniqueTranslation::for(Product::class, 'slug')->localeFrom('t.*.slug')->locale('en')],
    'ignore of another model type' => [fn (): UniqueTranslation => UniqueTranslation::for(Product::class, 'slug')->ignore(Clinic::query()->create())],
    'ignore of an unsaved model' => [fn (): UniqueTranslation => UniqueTranslation::for(Product::class, 'slug')->ignore(new Product)],
    'a column expression' => [fn (): UniqueTranslation => UniqueTranslation::for(Product::class, 'slug')->where('a; drop table x', 1)],
    'a model without the declaration' => [fn (): UniqueTranslation => UniqueTranslation::for(Plain::class, 'slug')],
]);

it('rejects an unknown column', function (): void {
    expect(fn (): UniqueTranslation => UniqueTranslation::for(Product::class, 'sku'))->toThrow(UnknownTranslationAttribute::class);
});

it('fails loudly when the attribute does not match the localeFrom pattern', function (): void {
    $rule = UniqueTranslation::for(Product::class, 'slug')->localeFrom('translations.*.slug');

    expect(fn () => Validator::make(['slug' => 'x'], ['slug' => $rule])->fails())->toThrow(InvalidTranslationDefinition::class);
});

it('neither requires nor queries null, missing and empty values', function (): void {
    product()->saveLocales(['en' => ['slug' => 'black']]);
    $rule = UniqueTranslation::for(Product::class, 'slug')->locale('en');

    DB::enableQueryLog();

    expect(passes(['slug' => null], ['slug' => ['nullable', $rule]]))->toBeTrue()
        ->and(passes(['slug' => ''], ['slug' => ['nullable', $rule]]))->toBeTrue()
        ->and(passes([], ['slug' => ['sometimes', $rule]]))->toBeTrue()
        ->and(DB::getQueryLog())->toBe([]);
});

it('fails for a value that is not a string', function (): void {
    $rule = UniqueTranslation::for(Product::class, 'slug')->locale('en');

    expect(passes(['slug' => ['x']], ['slug' => $rule]))->toBeFalse();
});

it('narrows with where conditions on translation table columns', function (): void {
    product()->saveLocales(['en' => ['slug' => 'black', 'name' => 'Black']]);
    $rule = fn (UniqueTranslation $rule): bool => passes(['slug' => 'black'], ['slug' => $rule]);
    $base = fn (): UniqueTranslation => UniqueTranslation::for(Product::class, 'slug')->locale('en');

    expect($rule($base()->where('name', 'Black')))->toBeFalse()
        ->and($rule($base()->where('name', 'White')))->toBeTrue()
        ->and($rule($base()->whereNot('name', 'Black')))->toBeTrue()
        ->and($rule($base()->whereNull('description')))->toBeFalse()
        ->and($rule($base()->whereNotNull('description')))->toBeTrue();
});

it('uses the configured table and connection of the model', function (): void {
    config()->set('database.connections.other', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $model = new Product;
    $model->setConnection('other');

    $rule = UniqueTranslation::for($model, 'slug')->locale('en');

    expect(fn (): bool => passes(['slug' => 'x'], ['slug' => $rule]))->toThrow(QueryException::class);
});
