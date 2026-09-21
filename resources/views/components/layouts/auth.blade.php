<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'eReserve' }}</title>
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#2442ba">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="auth-page">
    <main class="auth-wrap">
        {{ $slot }}
    </main>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js');
            });
        }
    </script>
</body>
</html>
