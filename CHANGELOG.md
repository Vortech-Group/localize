# Changelog

All notable changes to `laravel-localize` will be documented in this file

## 0.2.0 - 2026-10-19

### Added

- `#[Localized('title', 'features', casts: ['features' => 'array'])]`: a translated column can hold an array. It is stored as JSON, written from an array by `update()` and `saveLocales()` and read back as one by the view (`toArray()`, `toJson()`, `magic`), with per-column fallback (`[]` is kept, `null` falls back). `localize:table` generates a `json` column for it
- `TranslationChanged` carries the JSON strings of an array column and only fires when the decoded value changed, regardless of key order
- Unknown casts, casts on undeclared columns and non-array values throw

## 0.1.0 - 2026-10-18

### Added

- `#[Localized('slug', 'name', ...)]` and the `HasLocales` trait: a model declares its translatable columns, and each model gets its own translation table (`product_translations`, `clinic_translations`) with real, typed columns and no translation classes to write
- `locale()`: a `LocaleView` of the model in one locale. Reads fall back per column to the current app locale, then to `null`; `fallback: false` reads only the requested locale. The view fixes the locale context when it is created and keeps no copy of the data
- `LocaleView::update()` and `saveLocales()`: immediate, partial, transactional writes that share one writer. Omitted locales and columns stay, `null` clears, empty payloads are a no-op, and the whole payload is validated before the first query. Rows are inserted or updated, never upserted, so a unique-index clash cannot rewrite another owner's row
- `locales()`: the stored rows as a `HasMany` relation, for eager loading, `whereHas()` and the lazy-loading ban
- `UniqueTranslation` rule: per-locale uniqueness against the translation table with a fixed locale, a `localeFrom('translations.*.slug')` wildcard, `ignore($model)`, `where*()` filters and the `$model->locale('en')->unique('slug')` shorthand
- `withLocales()` factory macro: saves locales after a factory creates a model, as a full payload, one set of values for several locales, or one value set per locale
- `Route::localized()` and the `SetLocale` middleware: a `{locale}` URL prefix that sets the app locale (404 for unsupported ones), `URL::defaults()` so `route()` keeps the locale, and the parameter hidden from controllers. Runs before route model binding. Locales come from the new `locales` config key or the group's second argument
- `#[LocalizedSlug]`: slugs generated per locale from another column, with locale-aware transliteration and `-2`, `-3` suffixes against other models' slugs in the same locale; an explicit slug or `null` is respected and `regenerate: true` follows the source column
- Route model binding on translated slugs: with `#[LocalizedSlug]` the slug is the route key, `{article}` binds in the URL's locale without fallback and `route('articles.show', $article)` builds the URL; `{article:id}` still binds by id
- `LocaleLinks`, `<x-localize::head />` and `<x-localize::switcher />`: the URL of the current page in every locale (switcher), canonical and hreflang links; locales without a slug are unavailable
- `TranslationChanged` event, dispatched after commit for every translated column that changed
- `whereLocale()`, `whereLocales()`, `whereHasLocale()` and `withLocales()` query scopes: find models by a column in one or more locales, and eager load all or some locales (always with the app locale for the fallback)
- `#[Localized(..., magic: true)]`: opt-in reading of a translated column as a property (`$model->name` is the current app locale's value, `locale('hu')->name` still works); assigning it throws
- `toArray()`, `toJson()` and JSON serialization on the view, in declaration order
- `php artisan localize:table Product` generates the migration of a model's translation table (and asks for the model with Laravel Prompts when none is given): key type, column types, the owner/locale unique index and the slug index
- `php artisan localize:install` publishes the config file: `locale_length`, `table_suffix` and `locales`
- Exceptions: `InvalidTranslationDefinition`, `InvalidTranslationPayload`, `UnknownTranslationAttribute`, `UnsavedTranslationOwner`
- A Laravel Boost guideline and a `localize-development` skill ship with the package (`resources/boost`), so AI agents working in an application that uses `laravel-localize` pick up how to declare models, read and write locales, validate, query and route
- PHP 8.4+ with Laravel 13; tested on SQLite, MySQL and PostgreSQL
