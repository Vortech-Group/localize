<?php

declare(strict_types=1);

namespace Vortech\Localize\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Vortech\Localize\LocalizeServiceProvider;
use Vortech\Localize\TranslationMetadata;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LocalizeServiceProvider::class];
    }

    /**
     * Runs against SQLite unless DB_CONNECTION selects mysql or pgsql (CI does).
     */
    protected function defineEnvironment($app): void
    {
        $driver = env('DB_CONNECTION', 'sqlite');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', match ($driver) {
            'mysql', 'pgsql' => [
                'driver' => $driver,
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', $driver === 'mysql' ? '3306' : '5432'),
                'database' => env('DB_DATABASE', 'localize'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
                'collation' => $driver === 'mysql' ? 'utf8mb4_unicode_ci' : null,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::dropAllTables();
        TranslationMetadata::flush();

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->unique();
            $table->timestamps();
        });

        Schema::create('product_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('locale', 35);
            $table->string('slug')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'locale']);
            $table->unique(['locale', 'slug']);
        });

        Schema::create('clinics', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('clinic_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('locale', 35);
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['clinic_id', 'locale']);
        });

        Schema::create('showcases', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('showcase_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('showcase_id')->constrained('showcases')->cascadeOnDelete();
            $table->string('locale', 35);
            $table->string('title')->nullable();
            $table->json('features')->nullable();
            $table->timestamps();

            $table->unique(['showcase_id', 'locale']);
        });

        Schema::create('articles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('article_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('article_id')->constrained('articles')->cascadeOnDelete();
            $table->string('locale', 35);
            $table->string('slug')->nullable();
            $table->string('title')->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'locale']);
            $table->unique(['locale', 'slug']);
        });

        Schema::create('legacy_items', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('legacy_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('legacy_items')->cascadeOnDelete();
            $table->string('locale', 35);
            $table->string('label')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'locale']);
        });
    }
}
