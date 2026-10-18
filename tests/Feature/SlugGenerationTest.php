<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Vortech\Localize\Attributes\Localized;
use Vortech\Localize\Attributes\LocalizedSlug;
use Vortech\Localize\Exceptions\InvalidTranslationDefinition;
use Vortech\Localize\HasLocales;
use Vortech\Localize\Tests\Fixtures\Article;
use Vortech\Localize\Tests\Fixtures\RegeneratingArticle;
use Vortech\Localize\TranslationMetadata;

function article(array $locales = []): Article
{
    return Article::query()->create()->saveLocales($locales);
}

function slugOf(Article $article, string $locale): ?string
{
    return $article->locale($locale, fallback: false)->slug;
}

it('generates the slug from the source column when it is omitted', function (): void {
    $article = article(['en' => ['title' => 'Black T-shirt']]);

    expect(slugOf($article, 'en'))->toBe('black-t-shirt');
});

it('transliterates according to the locale', function (): void {
    $article = article(['hu' => ['title' => 'Fekete póló, őszi kabát'], 'de' => ['title' => 'Schöne Grüße']]);

    expect(slugOf($article, 'hu'))->toBe('fekete-polo-oszi-kabat')
        ->and(slugOf($article, 'de'))->toBe('schoene-gruesse');
});

it('handles locales with a region', function (): void {
    $article = article(['pt_BR' => ['title' => 'Ação Rápida'], 'en-GB' => ['title' => 'Colour Wheel']]);

    expect(slugOf($article, 'pt_BR'))->toBe('acao-rapida')
        ->and(slugOf($article, 'en-GB'))->toBe('colour-wheel');
});

it('adds a number when another owner has the slug in the same locale', function (): void {
    article(['en' => ['title' => 'Black T-shirt']]);
    $second = article(['en' => ['title' => 'Black T-shirt']]);
    $third = article(['en' => ['title' => 'Black T-shirt']]);

    expect(slugOf($second, 'en'))->toBe('black-t-shirt-2')
        ->and(slugOf($third, 'en'))->toBe('black-t-shirt-3');
});

it('does not number a slug for the same title in another locale', function (): void {
    article(['en' => ['title' => 'Taxi']]);
    $other = article(['hu' => ['title' => 'Taxi']]);

    expect(slugOf($other, 'hu'))->toBe('taxi');
});

it('does not treat a longer slug with the same prefix as taken', function (): void {
    article(['en' => ['title' => 'Black T-shirt long']]);

    expect(slugOf(article(['en' => ['title' => 'Black T-shirt']]), 'en'))->toBe('black-t-shirt');
});

it('keeps the slug on later saves of the title', function (): void {
    $article = article(['en' => ['title' => 'Black T-shirt']]);

    $article->locale('en')->update(['title' => 'Completely different']);

    expect(slugOf($article, 'en'))->toBe('black-t-shirt');
});

it('fills in a missing slug on a later save', function (): void {
    $article = article(['en' => ['title' => 'Old']]);
    DB::table('article_translations')->update(['slug' => null]);

    $article->locale('en')->update(['title' => 'New title']);

    expect(slugOf($article->refresh(), 'en'))->toBe('new-title');
});

it('regenerates when asked to, keeping its own slug free', function (): void {
    $article = RegeneratingArticle::query()->create()->saveLocales(['en' => ['title' => 'First']]);

    $article->locale('en')->update(['title' => 'Second']);
    expect(slugOf($article, 'en'))->toBe('second');

    $article->locale('en')->update(['title' => 'Second']);
    expect(slugOf($article, 'en'))->toBe('second');
});

it('stores an explicit slug as given and an explicit null as null', function (): void {
    $article = article(['en' => ['title' => 'Black T-shirt', 'slug' => 'my-own']]);
    expect(slugOf($article, 'en'))->toBe('my-own');

    $article->locale('en')->update(['slug' => null]);
    expect(slugOf($article, 'en'))->toBeNull();

    $article->locale('en')->update(['title' => 'Again']);
    expect(slugOf($article, 'en'))->toBe('again');
});

it('does not generate without a usable source', function (): void {
    $article = article(['en' => ['title' => '!!!'], 'hu' => ['title' => ''], 'de' => ['title' => null]]);

    expect(slugOf($article, 'en'))->toBeNull()
        ->and(slugOf($article, 'hu'))->toBeNull()
        ->and(slugOf($article, 'de'))->toBeNull();
});

it('does not generate when the source column is not part of the save', function (): void {
    $article = article(['en' => ['slug' => null]]);
    $article->locale('en')->update([]);

    expect(slugOf($article, 'en'))->toBeNull();
});

it('rolls the generated slug back with the rest on failure', function (): void {
    $article = article();

    expect(fn (): Article => $article->saveLocales(['en' => ['title' => 'A'], 'hu' => ['title' => 12]]))->toThrow(Exception::class)
        ->and(DB::table('article_translations')->count())->toBe(0);
});

it('rejects an invalid slug declaration', function (Model $model): void {
    expect(fn (): TranslationMetadata => TranslationMetadata::of($model))->toThrow(InvalidTranslationDefinition::class);
})->with([
    'undeclared source' => [fn (): Model => new #[Localized('slug')] #[LocalizedSlug(from: 'name')] class extends Model
    {
        use HasLocales;
    }],
    'same column twice' => [fn (): Model => new #[Localized('slug')] #[LocalizedSlug(from: 'slug', to: 'slug')] class extends Model
    {
        use HasLocales;
    }],
]);
