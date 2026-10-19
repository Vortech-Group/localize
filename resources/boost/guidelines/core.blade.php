## Localize (vortech/laravel-localize)

Typed translation tables for Eloquent: one table per model with real columns, an explicit locale view and transactional saves. Activate the `localize-development` skill when working with `#[Localized]`, `#[LocalizedSlug]`, `HasLocales`, `locale()`, `saveLocales()`, `UniqueTranslation`, `Route::localized()`, `LocaleLinks` or any `localize:*` command.

- Declare the translatable columns with `#[Localized('slug', 'name', ...)]` and add the `HasLocales` trait. The columns are not on the model's table and not in its `$fillable`.
- Generate the table with `php artisan localize:table` (asks for the model) and review the column types before `migrate`. The `(owner_id, locale)` unique index is required.
- Read with `$model->locale('en')->name`; never `$model->name`, unless the model declares `#[Localized(..., magic: true)]`, which makes `$model->name` read the current app locale (assigning it throws). Fallback is fixed: requested locale, then the current app locale, then `null`, per column. `fallback: false` reads only what is stored; use it for edit forms.
- Write with `$model->locale('en')->update([...])` or `$model->saveLocales(['hu' => [...], 'en' => [...]])`. Omitted locales and columns stay, `null` clears, `[]` is a no-op, an unknown column throws. The model must be saved first, and the package never saves the model's own changes.
- Query with `whereLocale('name', 'en')`, `whereLocales('name', ['en', 'hu'])`, `whereHasLocale('en', fn ($query) => $query->where('slug', ...))` and `withLocales()`. The relation is `locales()`, not `translations()`. A query never uses the display fallback.
- Validate uniqueness with `UniqueTranslation::for(Model::class, 'slug')->locale('en')` or `->localeFrom('translations.*.slug')`, plus `->ignore($model)` on update, or `$model->locale('en')->unique('slug')`. Which locales and columns are required is the Form Request's decision, not the package's.
- With `#[LocalizedSlug]` the slug is generated per locale and is the route key: bind `{article}`, use `{article:id}` for the id, and register routes with `Route::localized()`.
- Do not use `UniqueTranslation` or the generated slug as a replacement for the unique index.
