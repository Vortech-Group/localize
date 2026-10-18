@props(['labels' => []])

<nav {{ $attributes }} aria-label="{{ $attributes->get('aria-label', 'Language') }}">
    <ul>
        @foreach (app(\Vortech\Localize\Routing\LocaleLinks::class)->all() as $link)
            <li>
                @if ($link->available())
                    <a href="{{ $link->url }}" hreflang="{{ $link->hreflang() }}" lang="{{ $link->hreflang() }}" @if ($link->current) aria-current="true" @endif>{{ $labels[$link->locale] ?? strtoupper($link->hreflang()) }}</a>
                @else
                    <span lang="{{ $link->hreflang() }}" aria-disabled="true">{{ $labels[$link->locale] ?? strtoupper($link->hreflang()) }}</span>
                @endif
            </li>
        @endforeach
    </ul>
</nav>
