<?php

declare(strict_types=1);

namespace Vortech\Localize\Tests\Fixtures;

use Vortech\Localize\Attributes\Localized;

#[Localized('name')]
class PremiumProduct extends Product {}
