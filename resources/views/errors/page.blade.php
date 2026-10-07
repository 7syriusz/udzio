<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('errors.title', ['status' => $exception->getStatusCode()]) }} — {{ config('app.name') }}</title>
</head>
<body style="font-family: sans-serif; margin: 4rem auto; max-width: 32rem; padding: 0 1rem;">
    <h1>{{ \App\Http\Exceptions\UserFacingMessage::for($exception) }}</h1>
    <p>{{ __('errors.code', ['status' => $exception->getStatusCode()]) }}</p>
    @if ($exception->getMessage() === 'access.mfa_required')
        <p><a href="{{ route('account.security') }}">{{ __('access.mfa_setup') }}</a></p>
    @endif
    @php($previous = url()->previous())
    @if (str_starts_with($previous, url('/')) && ($previous !== url()->current() || ! request()->isMethod('GET')))
        <p><a href="{{ $previous }}">{{ __('errors.back') }}</a></p>
    @endif
    <p><a href="{{ url('/') }}">{{ __('errors.home') }}</a></p>
</body>
</html>
