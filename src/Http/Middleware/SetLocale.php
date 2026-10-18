<?php

declare(strict_types=1);

namespace Vortech\Localize\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Vortech\Localize\Exceptions\InvalidTranslationDefinition;

/**
 * Sets the app locale from the {locale} route parameter.
 *
 * The locale also becomes a URL default, so route('products.show', $product)
 * keeps the current locale without passing it, and the parameter is removed
 * from the route so controllers never receive it. An unsupported locale is a
 * 404. The supported list is `localize.locales`, or the middleware parameters
 * (`SetLocale::class.':hu,en'`).
 */
final class SetLocale
{
    public const string PARAMETER = 'locale';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$locales): Response
    {
        $route = $request->route();

        if (! $route instanceof Route || ! $route->hasParameter(self::PARAMETER)) {
            throw InvalidTranslationDefinition::invalid(sprintf('SetLocale needs a {%s} route parameter: register the routes with Route::localized().', self::PARAMETER));
        }

        $locale = $route->parameter(self::PARAMETER);

        if (! is_string($locale) || ! in_array($locale, self::supported(array_values($locales)), true)) {
            abort(404);
        }

        app()->setLocale($locale);
        URL::defaults([self::PARAMETER => $locale]);
        $route->forgetParameter(self::PARAMETER);

        return $next($request);
    }

    /**
     * @param  list<string>  $locales
     * @return list<string>
     */
    public static function supported(array $locales = []): array
    {
        if ($locales !== []) {
            return $locales;
        }

        $configured = config('localize.locales', []);

        return is_array($configured) ? array_values(array_filter($configured, is_string(...))) : [];
    }
}
