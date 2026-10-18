<?php

declare(strict_types=1);

namespace Vortech\Localize\Routing;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Vortech\Localize\Attributes\LocalizedSlug;
use Vortech\Localize\Exceptions\InvalidTranslationDefinition;
use Vortech\Localize\Http\Middleware\SetLocale;
use Vortech\Localize\LocaleView;
use Vortech\Localize\TranslationMetadata;

/**
 * The URLs of the current page in every locale, for a language switcher and
 * for canonical and hreflang links.
 *
 * It works on a named route registered with Route::localized(). A bound model
 * that has #[LocalizedSlug] contributes its slug of each locale; a locale in
 * which the model has no slug is not available. Other parameters are reused.
 */
final readonly class LocaleLinks
{
    public function __construct(
        private Request $request,
        private UrlGenerator $url,
    ) {}

    /**
     * Every supported locale, in configuration order. For a switcher, so the
     * query string is kept by default.
     *
     * @return list<LocaleLink>
     */
    public function all(bool $query = true): array
    {
        [$route, $name, $locales] = $this->context();
        $current = app()->getLocale();
        $links = [];

        foreach ($locales as $locale) {
            $links[] = new LocaleLink($locale, $this->urlFor($route, $name, $locale, $query), $locale === $current);
        }

        return $links;
    }

    /**
     * The locales the page exists in, without the query string: the hreflang alternates.
     *
     * @return list<LocaleLink>
     */
    public function alternates(): array
    {
        return array_values(array_filter($this->all(query: false), static fn (LocaleLink $link): bool => $link->available()));
    }

    /**
     * The page's own URL in the current locale, normalised and without the query string.
     */
    public function canonical(): string
    {
        [$route, $name] = $this->context();

        return $this->urlFor($route, $name, app()->getLocale(), false) ?? $this->url->current();
    }

    /**
     * @return array{Route, string, list<string>}
     */
    private function context(): array
    {
        $route = $this->request->route();

        if (! $route instanceof Route) {
            throw InvalidTranslationDefinition::invalid('There is no current route to build locale links for.');
        }

        $name = $route->getName();

        if ($name === null) {
            throw InvalidTranslationDefinition::invalid('Locale links need a named route.');
        }

        if (! in_array(SetLocale::PARAMETER, $route->parameterNames(), true)) {
            throw InvalidTranslationDefinition::invalid(sprintf('The route "%s" has no {%s} segment: register it with Route::localized().', $name, SetLocale::PARAMETER));
        }

        $group = $route->getAction('locales');
        $locales = is_array($group) ? array_values(array_filter($group, is_string(...))) : SetLocale::supported();

        return [$route, $name, $locales];
    }

    private function urlFor(Route $route, string $name, string $locale, bool $query): ?string
    {
        $parameters = [];

        foreach ($route->parameters() as $key => $value) {
            if ($value instanceof Model && $this->isSlugged($value, $route->bindingFieldFor($key))) {
                $value = $this->slugIn($value, $locale);

                if ($value === null) {
                    return null;
                }
            }

            $parameters[$key] = $value;
        }

        $parameters[SetLocale::PARAMETER] = $locale;

        return $this->url->route($name, $query ? $parameters + $this->request->query() : $parameters);
    }

    private function isSlugged(Model $model, ?string $bindingField): bool
    {
        if (! method_exists($model, 'getTranslationTable')) {
            return false;
        }

        $slug = TranslationMetadata::of($model)->slug;

        return $slug instanceof LocalizedSlug && ($bindingField === null || $bindingField === $slug->to);
    }

    private function slugIn(Model $model, string $locale): ?string
    {
        $slug = TranslationMetadata::of($model)->slug;
        $view = method_exists($model, 'locale') ? $model->locale($locale, fallback: false) : null;

        if (! $slug instanceof LocalizedSlug || ! $view instanceof LocaleView) {
            return null;
        }

        $value = $view->get($slug->to);

        return $value !== null && $value !== '' ? $value : null;
    }
}
