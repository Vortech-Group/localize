@php($links = app(\Vortech\Localize\Routing\LocaleLinks::class))
<link rel="canonical" href="{{ $links->canonical() }}">
@foreach ($links->alternates() as $link)
<link rel="alternate" hreflang="{{ $link->hreflang() }}" href="{{ $link->url }}">
@endforeach
