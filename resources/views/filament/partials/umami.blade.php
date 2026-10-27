{{-- Statistici de vizite fără cookie-uri (Umami): doar pe instanțele care au CEMAINOU_UMAMI_SRC și CEMAINOU_UMAMI_ID; altfel nimic. Numără doar pe domeniul instanței. --}}
@if (filled($src = config('cemainou.umami_src')) && filled($id = config('cemainou.umami_id')))
    <script defer src="{{ $src }}" data-website-id="{{ $id }}" data-domains="{{ parse_url(config('app.url'), PHP_URL_HOST) }}"></script>
@endif
