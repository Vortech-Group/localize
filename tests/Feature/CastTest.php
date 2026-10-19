<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Vortech\Localize\Attributes\Localized;
use Vortech\Localize\Events\TranslationChanged;
use Vortech\Localize\Exceptions\InvalidTranslationDefinition;
use Vortech\Localize\Exceptions\InvalidTranslationPayload;
use Vortech\Localize\HasLocales;
use Vortech\Localize\Tests\Fixtures\Showcase;
use Vortech\Localize\TranslationMetadata;

beforeEach(function (): void {
    app()->setLocale('hu');
});

function showcase(): Showcase
{
    return Showcase::query()->create();
}

it('writes an array to an array column and reads it back as an array', function (): void {
    $features = [['icon' => 'bolt', 'title' => 'Gyors', 'description' => 'Árvíztűrő tükörfúrógép']];
    $showcase = showcase()->saveLocales(['hu' => ['title' => 'Cím', 'features' => $features]]);

    expect($showcase->locale('hu')->features)->toBe($features)
        ->and($showcase->fresh()->locale('hu')->features)->toBe($features)
        ->and($showcase->locale('hu')->title)->toBe('Cím');
});

it('falls back per column for an array column', function (): void {
    $features = [['title' => 'Gyors']];
    $showcase = showcase()->saveLocales([
        'hu' => ['features' => $features],
        'en' => ['title' => 'Title'],
    ]);

    expect($showcase->locale('en')->features)->toBe($features)
        ->and($showcase->locale('en', fallback: false)->features)->toBeNull();
});

it('keeps an empty array instead of falling back, and null clears the column', function (): void {
    $showcase = showcase()->saveLocales(['hu' => ['features' => [['title' => 'A']]], 'en' => ['features' => []]]);

    expect($showcase->locale('en')->features)->toBe([]);

    $showcase->saveLocales(['en' => ['features' => null]]);

    expect($showcase->locale('en')->features)->toBe([['title' => 'A']])
        ->and($showcase->locale('en', fallback: false)->features)->toBeNull();
});

it('includes the decoded array in toArray and toJson', function (): void {
    $showcase = showcase()->saveLocales(['hu' => ['title' => 'Cím', 'features' => [['title' => 'A']]]]);

    expect($showcase->locale('hu')->toArray())->toBe(['title' => 'Cím', 'features' => [['title' => 'A']]])
        ->and(json_decode($showcase->locale('hu')->toJson(), true))->toBe(['title' => 'Cím', 'features' => [['title' => 'A']]]);
});

it('rejects a non-array value for an array column and a string for a plain one', function (mixed $features): void {
    expect(fn (): Showcase => showcase()->saveLocales(['hu' => ['features' => $features]]))->toThrow(InvalidTranslationPayload::class);
})->with([
    'a string' => ['[]'],
    'an int' => [1],
    'an unencodable value' => [[NAN]],
]);

it('writes nothing when an array column value is invalid', function (): void {
    $showcase = showcase();

    expect(fn (): Showcase => $showcase->saveLocales(['hu' => ['title' => 'Cím'], 'en' => ['features' => 'x']]))->toThrow(InvalidTranslationPayload::class)
        ->and($showcase->locale('hu', fallback: false)->title)->toBeNull();
});

it('dispatches the event with JSON strings, and only when the decoded value changed', function (): void {
    $showcase = showcase()->saveLocales(['hu' => ['features' => [['a' => 1, 'b' => 2]]]]);
    Event::fake([TranslationChanged::class]);

    $showcase->saveLocales(['hu' => ['features' => [['b' => 2, 'a' => 1]]]]);
    Event::assertNotDispatched(TranslationChanged::class);

    $showcase->saveLocales(['hu' => ['features' => [['a' => 1, 'b' => 3]]]]);
    Event::assertDispatched(TranslationChanged::class, fn (TranslationChanged $event): bool => $event->key === 'features'
        && json_decode((string) $event->oldValue, true) == [['a' => 1, 'b' => 2]]
        && $event->newValue === '[{"a":1,"b":3}]');
});

it('generates a json column for an array column in the migration', function (): void {
    File::deleteDirectory(database_path('migrations'));

    $this->artisan('localize:table', ['model' => Showcase::class])->assertSuccessful();

    $source = File::get(File::glob(database_path('migrations/*_create_showcase_translations_table.php'))[0]);
    File::deleteDirectory(database_path('migrations'));

    expect($source)->toContain("\$table->json('features')->nullable();")
        ->and($source)->toContain("\$table->string('title')->nullable();");
});

it('rejects a bad casts option', function (Closure $build): void {
    expect($build)->toThrow(InvalidTranslationDefinition::class);
})->with([
    'an unknown cast' => [fn (): Localized => new Localized('features', casts: ['features' => 'collection'])],
    'a list instead of a map' => [fn (): Localized => new Localized('features', casts: ['array'])],
    'a non-array option' => [fn (): Localized => new Localized('features', casts: 'array')],
]);

it('rejects a cast on a column that is not declared', function (): void {
    $model = new class extends Showcase {};

    expect(fn (): array => TranslationMetadata::casts($model::class))->not->toThrow(InvalidTranslationDefinition::class);

    $broken = new #[Localized('title', casts: ['features' => 'array'])] class extends Model
    {
        use HasLocales;
    };

    expect(fn (): array => TranslationMetadata::fields($broken::class))->toThrow(InvalidTranslationDefinition::class);
});

it('declares no casts by default', function (): void {
    expect(new Localized('slug', 'name')->casts)->toBe([]);
});
