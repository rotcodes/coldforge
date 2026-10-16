<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title inertia>ColdForge</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <script>
        // Apply theme before paint (dark by default) to avoid a flash.
        try { if (localStorage.getItem('theme') === 'light') document.documentElement.classList.remove('dark'); } catch (e) {}
    </script>
    @viteReactRefresh
    @vite(['resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
    @inertiaHead
</head>
<body class="font-sans antialiased">
    @inertia
</body>
</html>
