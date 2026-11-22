<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#f6f4ee">
    <meta name="csrf-token" content="{{ $csrf }}">
    <title>McNutt Legacy</title>
    @vite(['resources/css/app.css', 'resources/js/main.tsx'])
</head>
<body class="bg-canvas text-ink antialiased">
<noscript><p style="padding:2rem">McNutt Legacy needs JavaScript enabled.</p></noscript>
<div id="root"></div>
</body>
</html>
