<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Locale length
    |--------------------------------------------------------------------------
    |
    | The longest locale key a write accepts. It has to match the length of the
    | "locale" column in your translation tables; the migration examples in
    | the README use 35, enough for any BCP 47 tag.
    |
    */

    'locale_length' => 35,

    /*
    |--------------------------------------------------------------------------
    | Table suffix
    |--------------------------------------------------------------------------
    |
    | Appended to the singular of the model's table to get its translation
    | table: "products" becomes "product_translations". A model can still
    | override getTranslationTable() for an irregular name.
    |
    */

    'table_suffix' => '_translations',

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    |
    | The locales of Route::localized(): the {locale} URL segment accepts
    | exactly these, matched as written ("en-GB" is not "en"), and any other
    | segment is a 404. Writes to translations are not restricted by it.
    |
    */

    'locales' => ['en'],

];
