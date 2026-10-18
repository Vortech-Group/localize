<?php

declare(strict_types=1);

namespace Vortech\Localize;

use Illuminate\Database\Eloquent\Model;

/**
 * A stored translation row, hydrated from whichever translation table the
 * owning model points at.
 *
 * The table and connection live on the instance, never in static state, so
 * product and clinic translations can coexist in one process. Eloquent's
 * newInstance() copies both to every hydrated row.
 *
 * @internal
 *
 * @property string $locale
 */
final class TranslationRow extends Model
{
    protected $guarded = [];

    public static function for(TranslationMetadata $metadata): self
    {
        $row = new self;
        $row->setTable($metadata->table);
        $row->setConnection($metadata->connection);

        return $row;
    }
}
