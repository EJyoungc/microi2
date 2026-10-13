<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title . ' - ' . config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

{{-- @vite(['resources/css/app.css', 'resources/js/app.js']) --}}
<link rel="stylesheet" href="{{ asset('build/assets/app-4sMayH_F.css') }}">
<link rel="stylesheet" href="{{ asset('build/assets/fonts-C9MNnjVw.css ') }}">

<script type="module" src="{{ asset('build/assets/app-DzckNJh_.js') }}"></script>
<script type="module" src="{{ asset('build/assets/passkeys-ZWACIUjA.js') }}"></script>
@fluxAppearance
