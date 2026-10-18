<?php

declare(strict_types=1);

namespace Vortech\Localize\Attributes;

use Attribute;

/**
 * Generates the slug column of a translation from another column, unique per locale.
 *
 * A slug is generated while saving a locale only when that save sets `from`
 * and leaves `to` out, and the locale has no slug yet. A slug you pass is
 * stored as given, and an explicit null clears it. With `regenerate: true`
 * the slug follows every change of `from` instead of staying put.
 *
 * Both columns must be declared with #[Localized].
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class LocalizedSlug
{
    public function __construct(
        public string $from = 'name',
        public string $to = 'slug',
        public bool $regenerate = false,
    ) {}
}
