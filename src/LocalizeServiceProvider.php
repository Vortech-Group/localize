<?php

declare(strict_types=1);

namespace Vortech\Localize;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Vortech\Localize\Console\InstallCommand;
use Vortech\Localize\Exceptions\InvalidTranslationDefinition;
use Vortech\Localize\Http\Middleware\SetLocale;
use Vortech\Localize\Routing\LocaleLinks;

final class LocalizeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/localize.php', 'localize');

        $this->app->bind(LocaleLinks::class, static fn (Application $app): LocaleLinks => new LocaleLinks($app->make(Request::class), $app->make(UrlGenerator::class)));
    }

    public function boot(): void
    {
        // The locale has to be set before route model binding runs.
        $this->callAfterResolving(HttpKernel::class, static function (mixed $kernel): void {
            if ($kernel instanceof Kernel) {
                $kernel->addToMiddlewarePriorityBefore(SubstituteBindings::class, SetLocale::class);
            }
        });

        Router::macro('localized', function (Closure $routes, ?array $locales = null): void {
            $locales = SetLocale::supported($locales === null ? [] : array_values(array_filter($locales, is_string(...))));

            if ($locales === []) {
                throw InvalidTranslationDefinition::invalid('Route::localized() needs locales: set localize.locales or pass them as the second argument.');
            }

            /** @var Router $this */
            $this->group([
                'prefix' => '{'.SetLocale::PARAMETER.'}',
                'where' => [SetLocale::PARAMETER => implode('|', array_map(static fn (string $locale): string => preg_quote($locale, '#'), $locales))],
                'middleware' => SetLocale::class.':'.implode(',', $locales),
                'locales' => $locales,
            ], $routes);
        });

        Factory::macro('withLocales', function (string|array $locales, array $values = []): Factory {
            $payload = LocalePayload::from($locales, $values);

            /** @var Factory<Model> $this */
            return $this->afterCreating(static function (Model $model) use ($payload): void {
                if (method_exists($model, 'saveLocales')) {
                    $model->saveLocales($payload);
                }
            });
        });

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'localize');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/localize')], 'localize-views');
            $this->publishes([__DIR__.'/../config/localize.php' => $this->app->configPath('localize.php')], 'localize-config');

            $this->commands([InstallCommand::class]);
        }
    }
}
