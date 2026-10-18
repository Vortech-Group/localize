<?php

declare(strict_types=1);

namespace Vortech\Localize\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Vortech\Localize\HasLocales;

class Plain extends Model
{
    use HasLocales;
}
