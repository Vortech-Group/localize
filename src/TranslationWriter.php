<?php

declare(strict_types=1);

namespace Vortech\Localize;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use JsonException;
use stdClass;
use Vortech\Localize\Attributes\LocalizedSlug;
use Vortech\Localize\Events\TranslationChanged;
use Vortech\Localize\Exceptions\InvalidTranslationPayload;
use Vortech\Localize\Exceptions\UnknownTranslationAttribute;
use Vortech\Localize\Exceptions\UnsavedTranslationOwner;

/**
 * The single write path behind LocaleView::update() and saveLocales().
 *
 * The whole payload is validated before the first query. Existing rows are
 * updated with only the submitted columns, missing rows are inserted in
 * batches grouped by column set. Nothing here is an upsert on purpose: on MySQL
 * an upsert reacts to any unique index, so a clashing slug could rewrite
 * another owner's row. A clash raises a constraint violation instead.
 *
 * @internal
 */
final class TranslationWriter
{
    /** Room is left under the usual 255 for a numeric suffix. */
    private const int SLUG_LENGTH = 240;

    public static function localeLength(): int
    {
        $length = config('localize.locale_length', 35);

        return is_int($length) && $length > 0 ? $length : 35;
    }

    /**
     * @param  array<array-key, mixed>  $translations
     */
    public function write(Model $owner, array $translations): void
    {
        if (! $owner->exists) {
            throw UnsavedTranslationOwner::for($owner::class);
        }

        $metadata = TranslationMetadata::of($owner);
        $payload = $this->validated($owner, $metadata, $translations);

        if ($payload === []) {
            return;
        }

        $connection = $owner->getConnection();

        $events = $connection->transaction(fn (): array => $this->persist($connection, $owner, $metadata, $payload));

        if ($owner->relationLoaded('locales')) {
            $owner->load('locales');
        }

        if ($events !== []) {
            $dispatcher = resolve(Dispatcher::class);

            // Waits for an outer transaction, so a rolled back write announces nothing.
            $connection->afterCommit(static function () use ($dispatcher, $events): void {
                array_map($dispatcher->dispatch(...), $events);
            });
        }
    }

    /**
     * @param  array<array-key, mixed>  $translations
     * @return array<string, array<string, string|null>>
     */
    private function validated(Model $owner, TranslationMetadata $metadata, array $translations): array
    {
        $maxLength = self::localeLength();
        $payload = [];

        foreach ($translations as $locale => $values) {
            if (! is_string($locale) || $locale === '' || mb_strlen($locale) > $maxLength) {
                throw InvalidTranslationPayload::invalidLocale($locale, $maxLength);
            }

            if (! is_array($values)) {
                throw InvalidTranslationPayload::notAnArray($locale, $values);
            }

            $clean = [];

            foreach ($values as $field => $value) {
                if (! is_string($field) || ! in_array($field, $metadata->fields, true)) {
                    throw UnknownTranslationAttribute::for($owner::class, $field);
                }

                $clean[$field] = array_key_exists($field, $metadata->casts)
                    ? $this->encoded($locale, $field, $value)
                    : $this->plain($locale, $field, $value);
            }

            if ($clean !== []) {
                $payload[$locale] = $clean;
            }
        }

        return $payload;
    }

    private function plain(string $locale, string $field, mixed $value): ?string
    {
        if ($value !== null && ! is_string($value)) {
            throw InvalidTranslationPayload::invalidValue($locale, $field, $value);
        }

        return $value;
    }

    /**
     * The JSON of an `array` cast column; `null` clears it.
     */
    private function encoded(string $locale, string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw InvalidTranslationPayload::invalidCastValue($locale, $field, $value);
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidTranslationPayload::invalidCastValue($locale, $field, $value);
        }
    }

    /**
     * Whether a stored value and a new one differ. JSON is compared decoded and with
     * sorted keys, because the database may reformat or reorder what it stores.
     */
    private function differs(?string $old, ?string $new, bool $json): bool
    {
        if (! $json || $old === null || $new === null) {
            return $old !== $new;
        }

        return $this->canonical(json_decode($old, true)) !== $this->canonical(json_decode($new, true));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map($this->canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * Fills in the slug of every locale that sets the source column but not the slug.
     *
     * @param  array<string, array<string, string|null>>  $payload
     * @param  array<array-key, mixed>  $stored  locale => stored slug (or the locale itself when no slug is configured)
     * @return array<string, array<string, string|null>>
     */
    private function withSlugs(Connection $connection, TranslationMetadata $metadata, mixed $key, array $payload, array $stored): array
    {
        $rule = $metadata->slug;

        if (! $rule instanceof LocalizedSlug) {
            return $payload;
        }

        foreach ($payload as $locale => $values) {
            $source = $values[$rule->from] ?? null;
            $current = $stored[$locale] ?? null;

            if (array_key_exists($rule->to, $values) || $source === null || $source === '') {
                continue;
            }

            if (is_string($current) && $current !== '' && ! $rule->regenerate) {
                continue;
            }

            $slug = $this->uniqueSlug($connection, $metadata, $key, $locale, $rule->to, $source);

            if ($slug !== null) {
                $payload[$locale][$rule->to] = $slug;
            }
        }

        return $payload;
    }

    /**
     * The slug of the text, with -2, -3, ... added until no other owner has it in the locale.
     */
    private function uniqueSlug(Connection $connection, TranslationMetadata $metadata, mixed $key, string $locale, string $column, string $text): ?string
    {
        $language = strtolower(preg_split('/[-_]/', $locale)[0] ?? 'en');
        $base = mb_substr(Str::slug($text, '-', $language), 0, self::SLUG_LENGTH);
        $base = rtrim($base, '-');

        if ($base === '') {
            return null;
        }

        $taken = $connection->table($metadata->table)
            ->where('locale', $locale)
            ->where($metadata->foreignKey, '!=', $key)
            ->where(static function (Builder $query) use ($column, $base): void {
                $query->where($column, $base)->orWhere($column, 'like', $base.'-%');
            })
            ->pluck($column)
            ->all();

        $slug = $base;

        for ($number = 2; in_array($slug, $taken, true); $number++) {
            $slug = $base.'-'.$number;
        }

        return $slug;
    }

    /**
     * @param  array<string, array<string, string|null>>  $payload
     * @return list<TranslationChanged>
     */
    private function persist(Connection $connection, Model $owner, TranslationMetadata $metadata, array $payload): array
    {
        $key = $owner->getAttribute($metadata->ownerKey);
        $now = TranslationRow::for($metadata)->freshTimestampString();

        $rows = $connection->table($metadata->table)
            ->where($metadata->foreignKey, $key)
            ->whereIn('locale', array_keys($payload))
            ->get(['locale', ...$metadata->fields])
            ->keyBy('locale');
        $existing = array_map(strval(...), $rows->keys()->all());
        $stored = $rows->map(fn (object $row): mixed => $row->{$metadata->slug->to ?? 'locale'} ?? null)->all();

        $payload = $this->withSlugs($connection, $metadata, $key, $payload, $stored);

        $events = [];
        $inserts = [];

        foreach ($payload as $locale => $values) {
            $row = $rows->get($locale);

            foreach ($values as $field => $value) {
                $old = $row instanceof stdClass && is_string($row->{$field}) ? $row->{$field} : null;

                if ($this->differs($old, $value, array_key_exists($field, $metadata->casts))) {
                    $events[] = new TranslationChanged($owner, $field, $locale, $old, $value);
                }
            }

            if (in_array($locale, $existing, true)) {
                $connection->table($metadata->table)
                    ->where($metadata->foreignKey, $key)
                    ->where('locale', $locale)
                    ->update([...$values, 'updated_at' => $now]);

                continue;
            }

            ksort($values);
            $inserts[implode(',', array_keys($values))][] = [
                $metadata->foreignKey => $key,
                'locale' => $locale,
                ...$values,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach ($inserts as $batch) {
            $connection->table($metadata->table)->insert($batch);
        }

        return $events;
    }
}
