<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}" />

{{-- What the browser needs to reach Reverb, written per request rather than
     baked into the JS bundle: the entrypoint generates the key at start when
     .env has none, so it is only known at runtime. Signed-in pages only — the
     channels are private and a guest could not join them anyway. Host and
     port are the page's own; see resources/js/echo.js. --}}
@auth
    @if (config('broadcasting.default') === 'reverb' && filled(config('broadcasting.connections.reverb.key')))
        <meta name="reverb-key" content="{{ config('broadcasting.connections.reverb.key') }}" />
    @endif
@endauth

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="{{ route('favicon', App\Enums\ColorScheme::current()) }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

{{-- Installable as an app: the manifest here, the service worker registered
     in resources/js/app.js. The colour is the ground every scheme shares
     within a shade, so the window's title bar meets the page. --}}
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0c0c0d">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'retroBITE') }}">

@vite(['resources/css/app.css', 'resources/js/app.js'])
