<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

afterEach(function (): void {
    File::delete(config_path('localize.php'));
});

it('publishes the config file', function (): void {
    $this->artisan('localize:install')->assertSuccessful();

    expect(config_path('localize.php'))->toBeFile();
});

it('keeps an existing config file unless forced', function (): void {
    File::put(config_path('localize.php'), '<?php return [];');

    $this->artisan('localize:install')->assertSuccessful();
    expect(File::get(config_path('localize.php')))->toBe('<?php return [];');

    $this->artisan('localize:install', ['--force' => true])->assertSuccessful();
    expect(File::get(config_path('localize.php')))->toContain('locale_length');
});

it('merges the default config', function (): void {
    expect(config('localize.locale_length'))->toBe(35)
        ->and(config('localize.table_suffix'))->toBe('_translations')
        ->and(config('localize.locales'))->toBe(['en']);
});
