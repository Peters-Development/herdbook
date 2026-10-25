{{--
    End of <head> on every panel page (PanelsRenderHook::HEAD_END). Browsers that
    read SVG favicons take favicon.svg; the rest fall back to favicon.ico. The
    manifest gives "Add to home screen" on a phone the app icon and name.
--}}
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32" />
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml" />
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}" />
<link rel="manifest" href="{{ asset('manifest.json') }}" />
<meta name="theme-color" content="#17181b" />
