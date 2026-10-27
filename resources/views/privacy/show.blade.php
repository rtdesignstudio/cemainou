{{-- Confidențialitate: o pagină de sine stătătoare, în stilul cemainou (alb-negru, titluri mari), fără fonturi sau scripturi de la alții. --}}
<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $locale === 'ro' ? 'Confidențialitate' : 'Privacy' }} · cemainou</title>
    <style>
        :root { --ink: #0a0a0a; --mute: #737373; --line: #e5e5e5; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter Tight', 'Inter', -apple-system, 'Helvetica Neue', Arial, sans-serif; color: var(--ink); background: #fff; -webkit-font-smoothing: antialiased; }
        .wrap { max-width: 46rem; margin: 0 auto; padding: 0 1rem 4rem; }
        header { display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 0; border-bottom: 1px solid var(--line); font-size: .875rem; }
        header b { font-weight: 600; font-size: 1rem; }
        header a { color: var(--ink); }
        header nav { display: flex; gap: 1rem; align-items: baseline; }
        header .lang { font-size: .875rem; font-weight: 400; color: var(--mute); }
        header b.lang { color: var(--ink); font-weight: 600; }
        h1 { font-size: clamp(2.75rem, 8vw, 5rem); line-height: .95; letter-spacing: -.035em; font-weight: 700; margin: 3.5rem 0 1rem; }
        .lead { font-size: 1.25rem; line-height: 1.5; color: #262626; }
        .updated { margin-top: 1rem; font-size: .875rem; color: var(--mute); }
        h2 { font-size: 1.5rem; font-weight: 600; letter-spacing: -.02em; margin: 3rem 0 1rem; padding-top: 1rem; border-top: 1px solid var(--ink); }
        p, li { font-size: 1.0625rem; line-height: 1.6; }
        p + p, p + ul, ul + p { margin-top: .75rem; }
        ul { padding-left: 1.25rem; display: grid; gap: .4rem; }
        a { color: var(--ink); text-underline-offset: 3px; }
        b { font-weight: 600; }
        .box { margin-top: 1rem; padding: 1rem; border: 1px solid var(--line); }
    </style>
</head>
<body>
<div class="wrap">
    <header><b>cemainou</b><nav>
        @foreach (\App\Http\Middleware\SetLocale::LOCALES as $code => $name)
            @if ($code === $locale)<b class="lang" lang="{{ $code }}">{{ $name }}</b>@else<a class="lang" href="{{ route('locale.switch', $code) }}" lang="{{ $code }}" hreflang="{{ $code }}">{{ $name }}</a>@endif
        @endforeach
        <a href="{{ $back }}">{{ $locale === 'ro' ? 'Înapoi' : 'Back' }}</a>
    </nav></header>
    @include(view()->exists('privacy.'.$locale) ? 'privacy.'.$locale : 'privacy.en')
</div>
</body>
</html>
