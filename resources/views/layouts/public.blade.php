<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#f6f4ee">
    <title>@yield('title', 'McNutt Legacy')</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-canvas text-ink antialiased">
<main class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-6 py-12">
    <div class="mb-8 flex items-center gap-3">
        <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-pine text-sm font-semibold tracking-wide text-white" aria-hidden="true">ML</span>
        <span class="font-serif text-xl">McNutt Legacy</span>
    </div>
    <div class="rounded-2xl border border-line bg-surface p-8 shadow-sm">
        @yield('content')
    </div>
    <p class="mt-6 text-center text-xs text-muted">Private family office · {{ parse_url(config('app.url'), PHP_URL_HOST) }}</p>
</main>
</body>
</html>
