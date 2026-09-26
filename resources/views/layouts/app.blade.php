<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — {{ config('app.name') }}</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="min-h-screen bg-gray-50 text-gray-900">
    <header class="border-b bg-white">
        <nav class="mx-auto flex max-w-4xl flex-wrap items-center gap-4 px-4 py-3" aria-label="Konto">
            <a href="{{ route('account.show') }}" class="font-semibold">{{ config('app.name') }}</a>
            <a href="{{ route('account.show') }}" class="text-blue-700 underline">Moje dane</a>
            <a href="{{ route('account.contacts.index') }}" class="text-blue-700 underline">Kontakty</a>
            <a href="{{ route('account.represented.index') }}" class="text-blue-700 underline">Osoby reprezentowane</a>
            <a href="{{ route('account.security') }}" class="text-blue-700 underline">Bezpieczeństwo</a>
            <form method="POST" action="{{ route('logout') }}" class="ml-auto">
                @csrf
                <button type="submit" class="text-sm underline">Wyloguj</button>
            </form>
        </nav>
    </header>
    <main class="mx-auto max-w-4xl px-4 py-8">
        <h1 class="mb-6 text-2xl font-semibold">@yield('title')</h1>
        @if (session('status'))
            <p class="mb-4 rounded bg-green-50 p-3 text-green-800" role="status">{{ session('status') }}</p>
        @endif
        @yield('content')
    </main>
</body>
</html>
