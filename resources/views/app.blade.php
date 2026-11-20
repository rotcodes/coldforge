<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title inertia>ColdForge</title>
    <meta name="theme-color" content="#fafafa" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0b0c0e" media="(prefers-color-scheme: dark)">
    <link rel="icon" href="/favicon.svg?v=2" type="image/svg+xml">
    <script>
        // Apply theme before paint (dark by default) to avoid a flash.
        try { if (localStorage.getItem('theme') === 'light') document.documentElement.classList.remove('dark'); } catch (e) {}
    </script>
    @viteReactRefresh
    @vite('resources/js/app.tsx')
    @inertiaHead
</head>
<body class="font-sans antialiased">
    @inertia
</body>
</html>
