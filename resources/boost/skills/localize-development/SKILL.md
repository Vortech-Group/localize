---
name: localize-development
description: Build, query and debug translated Eloquent models with vortech/laravel-localize. Activate when working with #[Localized], #[LocalizedSlug], the HasLocales trait, locale(), saveLocales(), whereLocale(), whereLocales(), whereHasLocale(), withLocales(), UniqueTranslation, Route::localized(), SetLocale, LocaleLinks, the localize components, or the localize:install and localize:table commands, and when a write fails with UnknownTranslationAttribute, InvalidTranslationPayload, UnsavedTranslationOwner or InvalidTranslationDefinition.
---

# Localize development

Localize stores translations in a table of their own per model (`product_translations`) with real, typed columns. Reading goes through an explicit locale view, writing through one transactional writer. There are no translation classes and nothing is translated implicitly: `$product->name` is not magic unless the model opts in with `#[Localized(..., magic: true)]`.

## Setup

```bash
composer require vortech/laravel-localize
php artisan localize:install        # publishes config/localize.php (optional)
php artisan localize:table          # asks for a model; or: localize:table Product
php artisan migrate
```

`localize:table` writes the migration from the model: table name, key type (`foreignId`, `foreignUlid`, `foreignUuid`), nullable `string` columns (`text` for `body`, `content`, `description`, `excerpt`, `notes`, `summary`, `text`) and the unique indexes. **Check the column types before migrating.** `--force` replaces a migration generated earlier.

Config (`config/localize.php`): `locale_length` (35, must match the `locale` column), `table_suffix` (`_translations`), `locales` (the locales `Route::localized()` accepts).

## Declare a model

```php
#[Fillable('sku', 'price')]   // not the translated columns
#[Localized('slug', 'name', 'description')]
class Product extends Model
{
    use HasLocales;
}
```

- The list cannot be empty, entries must be plain column names, and `id`, `locale`, `created_at`, `updated_at` and the foreign key are refused. Duplicates are dropped.
- A subclass uses its own `#[Localized]`, or else the nearest parent's. Lists are never merged.
- The table is the singular of the model's table plus `table_suffix`; the key is the model's foreign key (`product_id`). Override `getTranslationTable()` and `getTranslationForeignKey()` for an irregular layout.
- The table must have the unique index `(owner_id, locale)`; add `(locale, slug)` if slugs must be unique.

## Read

```php
$product->locale()->name;                          // app locale
$product->locale('en')->name;                      // en, then the app locale, then null
$product->locale('en', fallback: false)->name;     // only what is stored for en
$product->locale('en')->get($column);              // column name in a variable
$product->locale('en')->toArray();                 // declared columns in order; also toJson(), JsonSerializable
```

- Fallback is per column and fixed: requested locale, current app locale, `null`. `null` and a missing row fall back; `''` and `'0'` are returned as stored. `app.fallback_locale` and "first existing language" are not steps.
- A view fixes the locale and the app locale when it is created. A later `app()->setLocale()` does not change it, and it keeps no copy of the data.
- Reading an undeclared column throws `UnknownTranslationAttribute`. An unsaved model returns `null` for everything, without a query.
- **Fill edit forms with `fallback: false`**, otherwise a user sees another language's text as a finished translation and saves it back.
- `$view->name = 'x'` throws; write with `update()`.
- **`casts`**: `#[Localized('title', 'features', casts: ['features' => 'array'])]` makes `features` take and return an array (stored as JSON; `localize:table` generates a `json` column). The cast on the owning model does not apply to translated columns. Only `array` is supported.
- **`magic: true`**: `#[Localized('name', magic: true)]` makes `$model->name` read the current app locale (like `$model->locale()->name`; `$model->locale('hu')->name` keeps working). It uses the `locales` relation, so eager load in lists. Assigning it (also through `fill()` or `create()`) throws `LogicException`; save with `locale()->update()` or `saveLocales()`. A real attribute with a value wins, and the array form never includes the column. Without the option `$model->name` is simply `null`.

## Write

```php
$product->locale('en')->update(['name' => 'Black T-shirt']);            // one locale, returns the view

$product->saveLocales([                                                  // several, one transaction, returns the model
    'hu' => ['name' => 'Új terméknév'],
    'en' => ['description' => null],
    'de' => [],
]);
```

| Input | Effect |
|---|---|
| omitted locale or column | unchanged |
| `null` | clears the column |
| `''` | stores an empty string |
| new locale | creates the row |
| `[]` | no-op |
| unknown column, a non-array locale, a value that is not string or null | exception, nothing is written |

- The whole payload is validated before the first query; everything then runs in one transaction on the model's connection, writing only the submitted columns.
- The model must be saved: `UnsavedTranslationOwner` otherwise. To create a model and its translations together use `DB::transaction(fn () => Model::create([...])->saveLocales([...]))`.
- Rows are inserted or updated, **never upserted**: a unique-index clash (a taken slug) throws a `QueryException` and rolls the call back instead of rewriting another model's row. Locale keys are used exactly as given (`en-GB`, `pt_BR`).
- Writes go through the query builder: no Eloquent events on the translation rows; instead `Vortech\Localize\Events\TranslationChanged` (model, key, locale, oldValue, newValue) is dispatched after commit for every changed column. After a save a loaded `locales` relation is reloaded.
- Never copy a fallback value into a locale; fallback is read-only.

## Query and eager load

```php
Product::query()
    ->whereHasLocale('en', fn ($query) => $query->where('slug', 'black-t-shirt'))
    ->withLocales()                 // or withLocales(['en']): those plus the app locale
    ->firstOrFail();

Product::withLocales()->get();      // the stored rows; no N+1 when reading
```

- The relation is `locales()` (a `HasMany` of stored rows), not the resolved values. `translations()` does not exist.
- Queries match stored rows only. Display fallback never changes what a query finds or sorts by.
- A view reads only the loaded rows. With a restricted eager load, load every locale you read **and** the app locale. Lazy loading follows the normal Laravel rules, including `Model::preventLazyLoading()`.

## Validate

```php
UniqueTranslation::for(Product::class, 'slug')->locale('en')
UniqueTranslation::for(Product::class, 'slug')->localeFrom('translations.*.slug')->ignore($product)
$product->locale('en')->unique('slug')        // that view's locale, ignores the model itself
->where('tenant_id', 5) / whereNot / whereNull / whereNotNull
```

- It checks the stored rows of the target locale only, with no fallback; the same value in another locale is fine. `null`, `''` and a missing value are skipped and the rule does not make a field required.
- `localeFrom()` takes a pattern with exactly one `*` (`en-GB` and `pt_BR` work). A pattern that does not match, or `locale()` plus `localeFrom()`, throws `InvalidTranslationDefinition`; there is no silent fallback to the app locale.
- `ignore()` takes a saved model of the same type, from route binding. Never build an exclusion from a request id.
- The Form Request decides which locales and columns are required (`'translations' => ['required', 'array:hu,en']`). The package checks only the shape: a locale key is a non-empty string within `locale_length`, the value an array, the column declared, the value a string or null.
- The rule does not replace the unique index; a concurrent write can still win.

## Slugs

```php
#[Localized('slug', 'title')]
#[LocalizedSlug(from: 'title', to: 'slug', regenerate: false)]   // defaults: 'name', 'slug'
```

- A slug is generated when a save sets `from`, leaves `to` out, and the locale has no slug yet. A slug you pass is stored as given, an explicit `null` clears it. `regenerate: true` follows the source column on every change.
- Transliteration follows the locale (`póló` is `polo` in `hu`). If another model has the slug in the same locale `-2`, `-3`... is added; the same title in another locale is not a clash. The unique index decides in the end.
- Both columns must be declared with `#[Localized]` and differ, otherwise `InvalidTranslationDefinition`.

## Routing

```php
Route::localized(function (): void {                    // {locale} prefix + SetLocale; Route::localized(fn, ['hu', 'en']) for one group
    Route::get('articles/{article}', [ArticleController::class, 'show'])->name('articles.show');
    Route::get('articles/{article:id}/edit', [ArticleController::class, 'edit'])->name('articles.edit');
});
```

- Locales come from `localize.locales` (default `['en']`, so set it); anything else is a 404. `SetLocale` sets the app locale, `URL::defaults(['locale' => ...])` (so `route()` keeps the locale) and removes the parameter from the route. It runs before route model binding.
- On a model with `#[LocalizedSlug]` the slug is the route key: `{article}` binds by the slug in the URL's locale with no fallback (another locale's slug is a 404) and loads `locales`. Use `{article:id}` for the id, and `{article}` rather than `{article:slug}` so `route('articles.show', $article)` can build the URL.

## Language switcher, canonical, hreflang

```blade
<x-localize::head />                                          {{-- canonical + hreflang alternates --}}
<x-localize::switcher :labels="['hu' => 'Magyar']" />         {{-- current marked, missing translations disabled --}}
```

```php
$links = app(LocaleLinks::class);
$links->all();          // every locale: locale, url (null when the page has no translation), current
$links->alternates();   // available only, no query string
$links->canonical();    // the page in the current locale, no query string
```

They need a **named route inside `Route::localized()`**, otherwise `InvalidTranslationDefinition`. Slug models contribute their slug of each locale. There is no `x-default`.

## Factories

```php
Product::factory()->withLocales(['hu' => ['name' => 'Póló'], 'en' => ['name' => 'Tee']])->create();
Product::factory()->withLocales(['hu', 'en'], ['name' => 'Póló'])->create();                  // same values
Product::factory()->withLocales(['hu', 'en'], [['name' => 'Póló'], ['name' => 'Tee']])->create();   // one set per locale
```

Saved after creation, so `make()` saves nothing.

## Exceptions

| Exception | Cause |
|---|---|
| `InvalidTranslationDefinition` | bad `#[Localized]` / `#[LocalizedSlug]`, missing trait, misconfigured rule, locale links outside a localized route |
| `InvalidTranslationPayload` | bad locale key, a non-array locale, a value that is not string or null |
| `UnknownTranslationAttribute` | an undeclared column read, written or used in a rule |
| `UnsavedTranslationOwner` | writing for a model that is not saved |

## Testing

Pest on Orchestra Testbench (SQLite by default; `DB_CONNECTION=mysql|pgsql` for the others). `composer test`, `composer analyse` (PHPStan max, strict rules), `composer lint`, `composer test:refactor`.
