{{-- Installable app (PWA): the manifest, the colours of the window / status bar and the home-screen icon. The service worker is registered in app.js. --}}
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}" data-sw="{{ asset('sw.js') }}">
<meta name="theme-color" content="#0f766e">
<meta name="application-name" content="TradeFlow">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="TradeFlow">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
