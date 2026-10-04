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
    <p><a href="{{ url('/') }}">{{ __('errors.home') }}</a></p>
</body>
</html>
