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
<body class="min-h-screen bg-ios-bg text-ios-label antialiased">
    <header class="border-b border-ios-separator bg-ios-card">
        <nav class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-1 gap-y-2 px-4 py-2" aria-label="{{ __('ui.nav.label') }}">
            <a href="{{ route('account.show') }}" class="mr-3 py-2 font-semibold">{{ config('app.name') }}</a>
            @foreach ([['account.show', 'account.show', 'ui.nav.account'], ['account.contacts.index', 'account.contacts.*', 'ui.nav.contacts'], ['account.represented.index', 'account.represented.*', 'ui.nav.represented'], ['account.security', 'account.security', 'ui.nav.security'], ['organizations.index', 'organizations.*', 'ui.nav.organizations']] as [$route, $pattern, $label])
                @php($current = request()->routeIs($pattern))
                <a href="{{ route($route) }}" @if ($current) aria-current="page" @endif
                   class="rounded-lg px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ios-blue {{ $current ? 'bg-ios-bg font-semibold text-ios-blue' : 'text-ios-label hover:bg-ios-bg' }}">{{ __($label) }}</a>
            @endforeach
            <form method="POST" action="{{ route('logout') }}" class="ml-auto" novalidate>
                @csrf
                <button type="submit" class="rounded-lg px-3 py-2 text-sm text-ios-secondary hover:bg-ios-bg focus-visible:outline-2 focus-visible:outline-ios-blue">{{ __('ui.nav.logout') }}</button>
            </form>
        </nav>
    </header>
    <main class="mx-auto max-w-3xl px-4 py-6">
        @hasSection('back')
            <div class="mb-2">@yield('back')</div>
        @endif
        <h1 class="mb-5 text-2xl font-bold break-words text-balance">@yield('title')</h1>
        @if ($status = \App\Http\StatusMessage::for(session('status')))
            <x-ui.notice tone="success">{{ $status }}</x-ui.notice>
        @endif
        @yield('content')
    </main>
</body>
</html>
