<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — {{ config('app.name') }}</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="min-h-screen bg-gray-50 text-gray-900">
    <main class="mx-auto mt-16 max-w-md rounded-lg bg-white p-8 shadow">
        <h1 class="mb-6 text-2xl font-semibold">@yield('title')</h1>
        @if (session('status'))
            <p class="mb-4 rounded bg-green-50 p-3 text-green-800" role="status">{{ session('status') }}</p>
        @endif
        @yield('content')
    </main>
</body>
</html>
