<?php

declare(strict_types=1);

namespace Vortech\Localize\Console;

use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use ReflectionClass;
use Throwable;
use Vortech\Localize\Attributes\LocalizedSlug;
use Vortech\Localize\Exceptions\InvalidTranslationDefinition;
use Vortech\Localize\HasLocales;
use Vortech\Localize\TranslationMetadata;
use Vortech\Localize\TranslationWriter;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Signature('localize:table
    {model : The model class, e.g. Product or App\\Models\\Product}
    {--force : Overwrite the migration if one was already generated}')]
#[Description('Generate the migration of a model\'s translation table')]
final class TableCommand extends Command implements PromptsForMissingInput
{
    /** Columns that are generated as text instead of string. */
    private const array TEXT_COLUMNS = ['body', 'content', 'description', 'excerpt', 'notes', 'summary', 'text'];

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    /**
     * Without a model argument the command asks for one: a list of the app's
     * models that use HasLocales, or a free class name when it finds none.
     *
     * @return array<string, Closure(): string>
     */
    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'model' => function (): string {
                $models = $this->models();

                if ($models === []) {
                    return text(label: 'Which model is the translation table for?', placeholder: 'App\\Models\\Product', required: true);
                }

                return (string) select(label: 'Which model is the translation table for?', options: $models, scroll: 12);
            },
        ];
    }

    public function handle(): int
    {
        $model = $this->resolveModel();

        if (! $model instanceof Model) {
            return self::FAILURE;
        }

        try {
            $metadata = TranslationMetadata::of($model);
        } catch (InvalidTranslationDefinition $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $directory = $this->laravel->databasePath('migrations');
        $existing = $this->files->glob($directory.'/*_create_'.$metadata->table.'_table.php');

        if ($existing !== [] && $this->option('force') !== true) {
            $this->components->warn(sprintf('A migration for "%s" already exists. Use --force to replace it.', $metadata->table));

            return self::FAILURE;
        }

        $this->files->delete($existing);

        $path = $directory.'/'.date('Y_m_d_His').'_create_'.$metadata->table.'_table.php';
        $this->files->ensureDirectoryExists($directory);
        $this->files->put($path, $this->migration($model, $metadata));

        $this->components->info(sprintf('Migration [%s] created. Check the column types, then run "php artisan migrate".', $path));

        return self::SUCCESS;
    }

    /**
     * The models of the app that use HasLocales: class => "class (table)".
     *
     * @return array<string, string>
     */
    private function models(): array
    {
        $namespace = $this->laravel->getNamespace();
        $models = [];

        foreach ($this->files->allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = $namespace.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            try {
                $usable = class_exists($class)
                    && is_subclass_of($class, Model::class)
                    && ! new ReflectionClass($class)->isAbstract()
                    && in_array(HasLocales::class, class_uses_recursive($class), true);

                if ($usable) {
                    $models[$class] = sprintf('%s (%s)', $class, TranslationMetadata::of(new $class)->table);
                }
            } catch (Throwable) {
                continue;
            }
        }

        ksort($models);

        return $models;
    }

    private function resolveModel(): ?Model
    {
        $argument = $this->argument('model');
        $name = is_string($argument) ? ltrim($argument, '\\') : '';
        $candidates = [$name, $this->laravel->getNamespace().'Models\\'.$name, $this->laravel->getNamespace().$name];

        foreach ($candidates as $class) {
            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                return new $class;
            }
        }

        $this->components->error(sprintf('Model "%s" was not found.', $name));

        return null;
    }

    private function migration(Model $model, TranslationMetadata $metadata): string
    {
        $schema = $metadata->connection === null ? 'Schema::' : sprintf("Schema::connection('%s')->", $metadata->connection);
        $length = TranslationWriter::localeLength();

        $columns = '';

        foreach ($metadata->fields as $field) {
            $type = in_array($field, self::TEXT_COLUMNS, true) ? 'text' : 'string';
            $columns .= sprintf("            \$table->%s('%s')->nullable();\n", $type, $field);
        }

        $indexes = sprintf("            \$table->unique(['%s', 'locale']);\n", $metadata->foreignKey);

        if ($metadata->slug instanceof LocalizedSlug) {
            $indexes .= sprintf("            \$table->unique(['locale', '%s']);\n", $metadata->slug->to);
        }

        return <<<PHP
        <?php

        declare(strict_types=1);

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                {$schema}create('{$metadata->table}', function (Blueprint \$table): void {
                    \$table->id();

        {$this->foreignKey($model, $metadata)}

                    \$table->string('locale', {$length});
        {$columns}            \$table->timestamps();

        {$indexes}        });
            }

            public function down(): void
            {
                {$schema}dropIfExists('{$metadata->table}');
            }
        };

        PHP;
    }

    private function foreignKey(Model $model, TranslationMetadata $metadata): string
    {
        $traits = class_uses_recursive($model);
        $constraint = $metadata->ownerKey === 'id' ? sprintf("'%s'", $model->getTable()) : sprintf("'%s', '%s'", $model->getTable(), $metadata->ownerKey);

        $column = match (true) {
            in_array(HasUlids::class, $traits, true) => sprintf("foreignUlid('%s')", $metadata->foreignKey),
            in_array(HasUuids::class, $traits, true), in_array(HasVersion4Uuids::class, $traits, true) => sprintf("foreignUuid('%s')", $metadata->foreignKey),
            default => sprintf("foreignId('%s')", $metadata->foreignKey),
        };

        return sprintf("            \$table->%s->constrained(%s)->cascadeOnDelete();\n", $column, $constraint);
    }
}
