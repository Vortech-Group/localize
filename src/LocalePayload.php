<?php

declare(strict_types=1);

namespace Vortech\Localize;

use InvalidArgumentException;

/**
 * Builds a locale => values payload from the shorthand forms that the
 * `withLocales()` factory macro accepts. The writer validates the result.
 *
 * @internal
 */
final class LocalePayload
{
    /**
     * - from(['hu' => [...], 'en' => [...]])                   the payload itself
     * - from(['hu', 'en'], ['name' => 'x'])                     the same values for every locale
     * - from(['hu', 'en'], [['name' => 'a'], ['name' => 'b']])  one list entry per locale
     *
     * @param  string|array<array-key, mixed>  $locales
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function from(string|array $locales, array $values = []): array
    {
        if ($values === []) {
            return is_array($locales) ? $locales : [];
        }

        $locales = array_values((array) $locales);
        $list = array_is_list($values);

        if ($list && count($locales) !== count($values)) {
            throw new InvalidArgumentException(sprintf('%d locales cannot be paired with %d value sets.', count($locales), count($values)));
        }

        $payload = [];

        foreach ($locales as $index => $locale) {
            if (! is_string($locale)) {
                throw new InvalidArgumentException('The locales must be strings.');
            }

            $payload[$locale] = $list ? $values[$index] : $values;
        }

        return $payload;
    }
}
