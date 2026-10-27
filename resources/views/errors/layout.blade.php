{{-- Paginile de eroare: alb, negru, linii fine, ca instalarea; un singur drum înapoi. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} - cemainou</title>
    @include('partials.favicon')
    @include('partials.plain-style')
    <style>.back { display: inline-flex; align-items: center; height: 2.9rem; padding: 0 1.5rem; background: #0a0a0a; color: #fff; font-weight: 500; text-decoration: none; margin-top: 1rem; }</style>
</head>
<body>
<main>
    <span class="brand">cemainou</span>
    <h1>{{ $title }}</h1>
    <p class="lead">{{ $lead }}</p>
    <a class="back" href="{{ url('/') }}">{{ __('Back to cemainou') }}</a>
</main>
</body>
</html>
