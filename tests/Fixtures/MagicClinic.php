<?php

declare(strict_types=1);

namespace Vortech\Localize\Tests\Fixtures;

use Vortech\Localize\Attributes\Localized;

#[Localized('name', magic: true)]
class MagicClinic extends Clinic
{
    protected $table = 'clinics';

    public function getTranslationForeignKey(): string
    {
        return 'clinic_id';
    }
}
