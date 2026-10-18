<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Vortech\Localize\Tests\Fixtures\Article;
use Vortech\Localize\Tests\Fixtures\Clinic;
use Vortech\Localize\Tests\Fixtures\Plain;
use Vortech\Localize\Tests\Fixtures\Product;

beforeEach(function (): void {
    File::deleteDirectory(database_path('migrations'));
});

afterEach(function (): void {
    File::deleteDirectory(database_path('migrations'));
});

function generated(string $table): string
{
    $files = File::glob(database_path("migrations/*_create_{$table}_table.php"));

    expect($files)->toHaveCount(1);

    return $files[0];
}

it('generates a migration that creates the translation table', function (): void {
    $this->artisan('localize:table', ['model' => Product::class])->assertSuccessful();

    $source = File::get(generated('product_translations'));

    expect($source)->toContain("Schema::create('product_translations'")
        ->and($source)->toContain("\$table->foreignId('product_id')->constrained('products')->cascadeOnDelete();")
        ->and($source)->toContain("\$table->string('locale', 35);")
        ->and($source)->toContain("\$table->string('slug')->nullable();")
        ->and($source)->toContain("\$table->text('description')->nullable();")
        ->and($source)->toContain("\$table->unique(['product_id', 'locale']);");
});

it('produces a migration that really creates a table the package can write to', function (): void {
    $this->artisan('localize:table', ['model' => Product::class])->assertSuccessful();
    Schema::drop('product_translations');

    (require generated('product_translations'))->up();

    $product = product('GEN')->saveLocales(['en' => ['name' => 'Tee', 'description' => 'Cotton']]);

    expect($product->locale('en', fallback: false)->description)->toBe('Cotton');

    (require generated('product_translations'))->down();
    expect(Schema::hasTable('product_translations'))->toBeFalse();
});

it('uses a ULID key, the slug index and works end to end', function (): void {
    $this->artisan('localize:table', ['model' => Article::class])->assertSuccessful();
    $source = File::get(generated('article_translations'));

    expect($source)->toContain("foreignUlid('article_id')")
        ->and($source)->toContain("\$table->unique(['locale', 'slug']);");

    Schema::drop('article_translations');
    (require generated('article_translations'))->up();

    $article = Article::query()->create()->saveLocales(['en' => ['title' => 'Black T-shirt']]);

    expect($article->locale('en', fallback: false)->slug)->toBe('black-t-shirt');
});

it('leaves out the slug index without a slug rule', function (): void {
    $this->artisan('localize:table', ['model' => Clinic::class])->assertSuccessful();

    expect(File::get(generated('clinic_translations')))->not->toContain("'locale', 'slug'");
});

it('fails for a model that does not exist', function (): void {
    $this->artisan('localize:table', ['model' => 'Nope'])->assertFailed();
});

it('does not overwrite an existing migration unless forced', function (): void {
    $this->artisan('localize:table', ['model' => Product::class])->assertSuccessful();
    $this->artisan('localize:table', ['model' => Product::class])->assertFailed();
    $this->artisan('localize:table', ['model' => Product::class, '--force' => true])->assertSuccessful();

    generated('product_translations');
});

it('refuses a model without the declaration', function (): void {
    $this->artisan('localize:table', ['model' => Plain::class])->assertFailed();
});

it('asks which model when none is given', function (): void {
    $directory = app_path('Models');
    File::ensureDirectoryExists($directory);
    File::put("$directory/Gadget.php", <<<'PHP'
        <?php

        namespace App\Models;

        use Illuminate\Database\Eloquent\Model;
        use Vortech\Localize\Attributes\Localized;
        use Vortech\Localize\HasLocales;

        #[Localized('name')]
        class Gadget extends Model
        {
            use HasLocales;
        }
        PHP);
    require_once "$directory/Gadget.php";

    $this->artisan('localize:table')
        ->expectsChoice('Which model is the translation table for?', 'App\Models\Gadget', ['App\Models\Gadget' => 'App\Models\Gadget (gadget_translations)'])
        ->assertSuccessful();

    expect(File::get(generated('gadget_translations')))->toContain("Schema::create('gadget_translations'");

    File::delete("$directory/Gadget.php");
});
