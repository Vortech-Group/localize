<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vortech\Localize\Attributes\Localized;
use Vortech\Localize\Exceptions\InvalidTranslationDefinition;
use Vortech\Localize\Tests\Fixtures\MagicClinic;
use Vortech\Localize\Tests\Fixtures\Plain;

beforeEach(function (): void {
    app()->setLocale('hu');
});

function magicClinic(): MagicClinic
{
    return MagicClinic::query()->create()->saveLocales(['hu' => ['name' => 'Rendelő'], 'en' => ['name' => 'Clinic']]);
}

it('reads a magic column in the current app locale', function (): void {
    $clinic = magicClinic();

    expect($clinic->name)->toBe('Rendelő');

    app()->setLocale('en');
    expect($clinic->name)->toBe('Clinic');

    app()->setLocale('de');
    expect($clinic->name)->toBeNull();
});

it('keeps the explicit locale view working next to it', function (): void {
    $clinic = magicClinic();

    expect($clinic->locale('hu')->name)->toBe('Rendelő')
        ->and($clinic->locale('en')->name)->toBe('Clinic')
        ->and($clinic->name)->toBe('Rendelő');
});

it('supports isset and null checks', function (): void {
    $clinic = magicClinic();

    expect(isset($clinic->name))->toBeTrue();

    app()->setLocale('de');
    expect(isset($clinic->name))->toBeFalse();
});

it('uses the loaded relation, so eager loading avoids queries', function (): void {
    magicClinic();
    $clinics = MagicClinic::query()->with('locales')->get();

    DB::enableQueryLog();
    $names = $clinics->pluck('name')->all();

    expect($names)->toBe(['Rendelő'])
        ->and(DB::getQueryLog())->toBe([]);
});

it('reads null for an unsaved model without a query', function (): void {
    DB::enableQueryLog();

    expect((new MagicClinic)->name)->toBeNull()
        ->and(DB::getQueryLog())->toBe([]);
});

it('refuses assigning a magic column, also through fill and create', function (): void {
    $clinic = magicClinic();

    expect(fn (): string => $clinic->name = 'x')->toThrow(LogicException::class)
        ->and(fn () => $clinic->fill(['name' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => MagicClinic::query()->create(['name' => 'x']))->toThrow(LogicException::class);
});

it('does not put the column into the array form or the dirty attributes', function (): void {
    $clinic = magicClinic();
    $clinic->name;

    expect($clinic->toArray())->not->toHaveKey('name')
        ->and($clinic->getDirty())->toBe([]);
});

it('leaves other attributes and non-magic models alone', function (): void {
    $clinic = magicClinic();

    expect($clinic->getKey())->not->toBeNull()
        ->and($clinic->created_at)->not->toBeNull()
        ->and(product()->saveLocales(['hu' => ['name' => 'A']])->name)->toBeNull();
});

it('does not break reading attributes of a model without a valid declaration', function (): void {
    $plain = new Plain;
    $plain->setRawAttributes(['title' => 'x']);

    expect($plain->title)->toBe('x');
});

it('rejects a bad #[Localized] option', function (Closure $build): void {
    expect($build)->toThrow(InvalidTranslationDefinition::class);
})->with([
    'a non-bool magic' => [fn (): Localized => new Localized('name', magic: 'yes')],
    'an unknown option' => [fn (): Localized => new Localized('name', magik: true)],
]);

it('still declares plain columns without the option', function (): void {
    $localized = new Localized('slug', 'name');

    expect($localized->fields)->toBe(['slug', 'name'])
        ->and($localized->magic)->toBeFalse();
});
