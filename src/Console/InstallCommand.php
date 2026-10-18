<?php

declare(strict_types=1);

namespace Vortech\Localize\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Vortech\Localize\LocalizeServiceProvider;

#[Signature('localize:install
    {--force : Overwrite the existing configuration file}')]
#[Description('Publish the Localize configuration file')]
final class InstallCommand extends Command
{
    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--provider' => LocalizeServiceProvider::class,
            '--tag' => 'localize-config',
            '--force' => $this->option('force'),
        ]);

        $this->components->info('Localize installed. Declare #[Localized(...)] on a model, add the HasLocales trait and create its translation table in a migration.');

        return self::SUCCESS;
    }
}
