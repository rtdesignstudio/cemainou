{{-- Pentru oameni, cât timp adminul aplică o versiune nouă (migrări nerulate): fără date, doar „reveniți într-un minut". --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta http-equiv="refresh" content="60">
    <title>{{ __('cemainou is being updated') }}</title>
    @include('partials.favicon')
    @include('partials.plain-style')
</head>
<body>
<main>
    <span class="brand">cemainou</span>
    <h1>{{ __('Being updated') }}</h1>
    <p class="lead">{{ __('cemainou is getting a new version. It takes a minute or two; this page reloads by itself.') }}</p>
</main>
</body>
</html>
