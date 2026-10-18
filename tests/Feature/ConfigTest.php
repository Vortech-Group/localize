<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('builds the translation table name from table_suffix', function (): void {
    config()->set('localize.table_suffix', '_i18n');

    expect(product()->getTranslationTable())->toBe('product_i18n');

    Schema::rename('product_translations', 'product_i18n');

    product('X')->saveLocales(['en' => ['name' => 'Tee']]);

    expect(DB::table('product_i18n')->value('name'))->toBe('Tee');
});
