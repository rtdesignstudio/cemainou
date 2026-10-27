{{-- Rezultatele căutării, pe feluri (App\Support\Search): ecranul de căutare și pagina „Toate rezultatele". Fiecare rând: titlul, fragmentul cu potrivirea, de unde și când. --}}
@foreach ($results as $kind => $items)
    <section class="wsa-search-group">
        <h3>{{ \App\Support\Search::label($kind) }} <span>{{ count($items) }}</span></h3>
        @foreach ($items as $item)
            <a href="{{ $item['url'] }}" class="wsa-search-item" data-result>
                <span class="wsa-search-title">{!! \App\Support\Search::mark($item['title'], $q) !!}</span>
                @if ($item['snippet'])<span class="wsa-search-snippet">{{ $item['snippet'] }}</span>@endif
                @if ($item['meta'])<span class="wsa-search-meta">{{ $item['meta'] }}</span>@endif
            </a>
        @endforeach
    </section>
@endforeach
