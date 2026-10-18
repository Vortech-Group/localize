<?php

declare(strict_types=1);

namespace Vortech\Localize\Routing;

/**
 * The current page in one locale. `url` is null when the page has no
 * translation (no slug) in that locale.
 */
final readonly class LocaleLink
{
    public function __construct(
        public string $locale,
        public ?string $url,
        public bool $current,
    ) {}

    public function available(): bool
    {
        return $this->url !== null;
    }

    /**
     * The locale as an hreflang or lang value: BCP 47 uses a hyphen ("pt-BR").
     */
    public function hreflang(): string
    {
        return str_replace('_', '-', $this->locale);
    }
}
