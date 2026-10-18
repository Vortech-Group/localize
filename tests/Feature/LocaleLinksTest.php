<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Vortech\Localize\Exceptions\InvalidTranslationDefinition;
use Vortech\Localize\Routing\LocaleLink;
use Vortech\Localize\Routing\LocaleLinks;
use Vortech\Localize\Tests\Fixtures\Article;

beforeEach(function (): void {
    config()->set('localize.locales', ['hu', 'en', 'pt_BR']);
    URL::defaults(['locale' => null]);

    Route::middleware(SubstituteBindings::class)->group(function (): void {
        Route::localized(function (): void {
            Route::get('articles/{article}', fn (Article $article): array => [
                'id' => $article->getKey(),
                'url' => route('articles.show', $article),
                'switcher' => array_map(fn (LocaleLink $link): array => [$link->locale, $link->url, $link->current], resolve(LocaleLinks::class)->all()),
                'alternates' => array_map(fn (LocaleLink $link): array => [$link->hreflang(), $link->url], resolve(LocaleLinks::class)->alternates()),
                'canonical' => resolve(LocaleLinks::class)->canonical(),
            ])->name('articles.show');

            Route::get('articles/{article:id}/edit', fn (Article $article): string => $article->getKey())->name('articles.edit');

            Route::get('head/{article}', fn (Article $article): string => Blade::render('<x-localize::head />'))->name('head');
            Route::get('switch/{article}', fn (Article $article): string => Blade::render('<x-localize::switcher :labels="[\'hu\' => \'Magyar\']" />'))->name('switch');

            Route::get('plain', fn (): string => resolve(LocaleLinks::class)->canonical())->name('plain');
            Route::get('anonymous', fn (): string => resolve(LocaleLinks::class)->canonical());
        });

        Route::get('/outside/{article:id}', fn (Article $article): string => resolve(LocaleLinks::class)->canonical())->name('outside');
    });

    Route::getRoutes()->refreshNameLookups();
});

function localizedArticle(): Article
{
    return article([
        'hu' => ['title' => 'Fekete póló'],
        'en' => ['title' => 'Black T-shirt'],
    ]);
}

it('binds the route key to the slug of the URL locale', function (): void {
    $article = localizedArticle();

    $this->getJson('/en/articles/black-t-shirt')->assertOk()->assertJsonPath('id', $article->getKey());
    $this->getJson('/hu/articles/fekete-polo')->assertOk()->assertJsonPath('id', $article->getKey());
});

it('does not bind a slug of another locale or an unknown slug', function (): void {
    localizedArticle();

    $this->getJson('/en/articles/fekete-polo')->assertNotFound();
    $this->getJson('/hu/articles/black-t-shirt')->assertNotFound();
    $this->getJson('/en/articles/nothing')->assertNotFound();
});

it('can still bind by id with an explicit binding field', function (): void {
    $article = localizedArticle();

    $this->get("/en/articles/{$article->getKey()}/edit")->assertOk()->assertSee($article->getKey());
});

it('generates URLs from the model with the slug of the current locale', function (): void {
    localizedArticle();

    $this->getJson('/en/articles/black-t-shirt')->assertJsonPath('url', url('/en/articles/black-t-shirt'));
});

it('loads the locales with the bound model, so links need no extra queries', function (): void {
    localizedArticle();
    DB::enableQueryLog();

    $this->getJson('/en/articles/black-t-shirt')->assertOk();

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'article_translations'))->count())->toBe(2);
});

it('builds the URL of the page in every locale, keeping the query string in the switcher', function (): void {
    localizedArticle();

    $response = $this->getJson('/en/articles/black-t-shirt?page=2')->assertOk();

    $response->assertJsonPath('switcher', [
        ['hu', url('/hu/articles/fekete-polo?page=2'), false],
        ['en', url('/en/articles/black-t-shirt?page=2'), true],
        ['pt_BR', null, false],
    ]);
});

it('lists only the available locales as hreflang alternates, without the query', function (): void {
    article(['hu' => ['title' => 'Fekete póló'], 'en' => ['title' => 'Black T-shirt'], 'pt_BR' => ['title' => 'Camiseta Preta']]);

    $this->getJson('/en/articles/black-t-shirt?page=2')
        ->assertJsonPath('alternates', [
            ['hu', url('/hu/articles/fekete-polo')],
            ['en', url('/en/articles/black-t-shirt')],
            ['pt-BR', url('/pt_BR/articles/camiseta-preta')],
        ]);
});

it('gives a canonical URL in the current locale without the query string', function (): void {
    localizedArticle();

    $this->getJson('/hu/articles/fekete-polo?utm_source=x')->assertJsonPath('canonical', url('/hu/articles/fekete-polo'));
});

it('limits the links to the locales of the group', function (): void {
    Route::middleware(SubstituteBindings::class)->group(function (): void {
        Route::localized(function (): void {
            Route::get('only/{article}', fn (Article $article): array => array_map(fn (LocaleLink $link): string => $link->locale, resolve(LocaleLinks::class)->all()))->name('only');
        }, ['hu', 'en']);
    });
    Route::getRoutes()->refreshNameLookups();
    localizedArticle();

    $this->getJson('/en/only/black-t-shirt')->assertOk()->assertExactJson(['hu', 'en']);
});

it('renders canonical and hreflang links with the head component', function (): void {
    article(['hu' => ['title' => 'Fekete póló'], 'pt_BR' => ['title' => 'Camiseta Preta']]);

    $html = $this->get('/pt_BR/head/camiseta-preta')->assertOk()->getContent();

    expect($html)->toContain('<link rel="canonical" href="'.url('/pt_BR/head/camiseta-preta').'">')
        ->and($html)->toContain('<link rel="alternate" hreflang="hu" href="'.url('/hu/head/fekete-polo').'">')
        ->and($html)->toContain('hreflang="pt-BR"')
        ->and($html)->not->toContain('hreflang="en"');
});

it('renders a switcher with the current locale marked and missing translations disabled', function (): void {
    localizedArticle();

    $html = $this->get('/en/switch/black-t-shirt')->assertOk()->getContent();

    expect($html)->toContain('>Magyar</a>')
        ->and($html)->toContain('aria-current="true"')
        ->and($html)->toContain('<span lang="pt-BR" aria-disabled="true">PT-BR</span>');
});

it('needs a named route inside a localized group', function (): void {
    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/en/anonymous'))->toThrow(InvalidTranslationDefinition::class);
});

it('refuses a route outside a localized group', function (): void {
    $article = localizedArticle();
    $this->withoutExceptionHandling();

    expect(fn () => $this->get("/outside/{$article->getKey()}"))->toThrow(InvalidTranslationDefinition::class);
});
