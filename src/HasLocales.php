<?php

declare(strict_types=1);

namespace Vortech\Localize;

use Closure;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use Vortech\Localize\Attributes\LocalizedSlug;
use Vortech\Localize\Exceptions\InvalidTranslationPayload;

/**
 * Adds translations to an Eloquent model that declares #[Localized(...)].
 *
 * @mixin Model
 */
trait HasLocales
{
    /**
     * The stored translation rows, one per locale. Not the resolved values:
     * those come from locale().
     *
     * @return HasMany<TranslationRow, $this>
     */
    public function locales(): HasMany
    {
        $metadata = TranslationMetadata::of($this);
        $row = TranslationRow::for($metadata);

        return new HasMany($row->newQuery(), $this, $row->qualifyColumn($metadata->foreignKey), $metadata->ownerKey);
    }

    /**
     * Models that have a row in the locale, optionally narrowed by a callback on
     * that row: whereLocale('en', fn ($query) => $query->where('slug', 'black')).
     * It matches stored rows only; there is no display fallback in a query.
     *
     * @param  Builder<static>  $query
     * @param  (Closure(Builder<TranslationRow>): mixed)|null  $callback
     */
    #[Scope]
    protected function whereLocale(Builder $query, string $locale, ?Closure $callback = null): void
    {
        $query->whereHas('locales', static function (Builder $rows) use ($locale, $callback): void {
            $rows->where('locale', $locale);

            if ($callback instanceof Closure) {
                $callback($rows);
            }
        });
    }

    /**
     * Eager loads the stored rows. With locales, only those and the current app
     * locale are loaded (the one the fallback reads), which is all a view in
     * one of them needs.
     *
     * @param  Builder<static>  $query
     * @param  string|list<string>|null  $locales
     */
    #[Scope]
    protected function withLocales(Builder $query, string|array|null $locales = null): void
    {
        if ($locales === null) {
            $query->with('locales');

            return;
        }

        $wanted = array_values(array_unique([...(array) $locales, app()->getLocale()]));

        $query->with(['locales' => static fn (Relation $rows) => $rows->getQuery()->getQuery()->whereIn('locale', $wanted)]);
    }

    /**
     * A view of this model in one locale. It fixes the requested locale and
     * the current app locale; later app()->setLocale() calls do not change it.
     */
    public function locale(?string $locale = null, bool $fallback = true): LocaleView
    {
        $appLocale = app()->getLocale();
        $locale ??= $appLocale;

        if ($locale === '') {
            throw InvalidTranslationPayload::invalidLocale($locale, TranslationWriter::localeLength());
        }

        return new LocaleView($this, $locale, $appLocale, $fallback);
    }

    /**
     * Saves several locales at once: locale => column => value. Omitted
     * locales and columns stay as they are, null clears a column.
     *
     * @param  array<array-key, mixed>  $translations
     */
    public function saveLocales(array $translations): static
    {
        new TranslationWriter()->write($this, $translations);

        return $this;
    }

    /**
     * With #[LocalizedSlug] the slug is the route key: {article} binds by the
     * slug in the current app locale, and route('articles.show', $article)
     * puts the current locale's slug into the URL. Use {article:id} for the id.
     */
    public function getRouteKeyName(): string
    {
        return TranslationMetadata::of($this)->slug->to ?? parent::getRouteKeyName();
    }

    public function getRouteKey(): mixed
    {
        $slug = TranslationMetadata::of($this)->slug;

        return $slug instanceof LocalizedSlug ? $this->locale(fallback: false)->get($slug->to) : parent::getRouteKey();
    }

    /**
     * A translated column is looked up in the current app locale only, with no
     * fallback, and the model comes back with its `locales` loaded.
     *
     * resolveRouteBinding() is overridden rather than resolveRouteBindingQuery(),
     * which HasUlids and HasUuids already define.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $field ??= $this->getRouteKeyName();

        if (! in_array($field, TranslationMetadata::fields(static::class), true)) {
            return parent::resolveRouteBinding($value, $field);
        }

        return $this->localizedBinding($value, $field)->first();
    }

    public function resolveSoftDeletableRouteBinding($value, $field = null)
    {
        $field ??= $this->getRouteKeyName();

        if (! in_array($field, TranslationMetadata::fields(static::class), true)) {
            return parent::resolveSoftDeletableRouteBinding($value, $field);
        }

        return $this->localizedBinding($value, $field)->withoutGlobalScope(SoftDeletingScope::class)->first();
    }

    /**
     * @return Builder<static>
     */
    private function localizedBinding(mixed $value, string $field): Builder
    {
        return $this->newQuery()
            ->whereHas('locales', static fn ($locales) => $locales->where('locale', app()->getLocale())->where($field, $value))
            ->with('locales');
    }

    /**
     * The translation table: the singular of the model's table plus _translations.
     */
    public function getTranslationTable(): string
    {
        $suffix = config('localize.table_suffix', '_translations');

        return Str::singular($this->getTable()).(is_string($suffix) ? $suffix : '_translations');
    }

    public function getTranslationForeignKey(): string
    {
        return $this->getForeignKey();
    }
}
