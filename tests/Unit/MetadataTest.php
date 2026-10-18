<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Vortech\Localize\Attributes\Localized;
use Vortech\Localize\Exceptions\InvalidTranslationDefinition;
use Vortech\Localize\HasLocales;
use Vortech\Localize\Tests\Fixtures\BudgetProduct;
use Vortech\Localize\Tests\Fixtures\Plain;
use Vortech\Localize\Tests\Fixtures\PremiumProduct;
use Vortech\Localize\Tests\Fixtures\Product;
use Vortech\Localize\TranslationMetadata;

it('reads the declared fields in order', function (): void {
    expect(TranslationMetadata::fields(Product::class))->toBe(['slug', 'name', 'description']);
});

it('inherits the nearest declaration without merging lists', function (): void {
    expect(TranslationMetadata::fields(PremiumProduct::class))->toBe(['name'])
        ->and(TranslationMetadata::fields(BudgetProduct::class))->toBe(['slug', 'name', 'description']);
});

it('resolves the table and key conventions from the model', function (): void {
    $metadata = TranslationMetadata::of(new Product);

    expect($metadata->table)->toBe('product_translations')
        ->and($metadata->foreignKey)->toBe('product_id')
        ->and($metadata->ownerKey)->toBe('id');
});

it('singularizes irregular table names', function (): void {
    $category = new #[Localized('name')] class extends Model
    {
        use HasLocales;

        protected $table = 'categories';
    };

    expect(TranslationMetadata::of($category)->table)->toBe('category_translations');
});

it('deduplicates the declared fields', function (): void {
    $model = new #[Localized('name', 'name', 'slug')] class extends Model
    {
        use HasLocales;
    };

    expect(TranslationMetadata::fields($model::class))->toBe(['name', 'slug']);
});

it('rejects an invalid declaration', function (Model $model): void {
    expect(fn (): TranslationMetadata => TranslationMetadata::of($model))->toThrow(InvalidTranslationDefinition::class);
})->with([
    'no attribute' => [fn (): Plain => new Plain],
    'no fields' => [fn (): Model => new #[Localized] class extends Model
    {
        use HasLocales;
    }],
    'a reserved column' => [fn (): Model => new #[Localized('locale')] class extends Model
    {
        use HasLocales;
    }],
    'the created_at column' => [fn (): Model => new #[Localized('created_at')] class extends Model
    {
        use HasLocales;
    }],
    'a json path' => [fn (): Model => new #[Localized('name->en')] class extends Model
    {
        use HasLocales;
    }],
    'an expression' => [fn (): Model => new #[Localized('lower(name)')] class extends Model
    {
        use HasLocales;
    }],
    'the foreign key' => [fn (): Model => new #[Localized('anonymous_id')] class extends Model
    {
        use HasLocales;

        public function getTranslationForeignKey(): string
        {
            return 'anonymous_id';
        }
    }],
    'no trait' => [fn (): Model => new #[Localized('name')] class extends Model {}],
]);
