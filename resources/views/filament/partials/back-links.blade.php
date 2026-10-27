{{-- Drumul înapoi, deasupra titlului paginii (App\Support\BackLinks); pe o sarcină deschisă dintr-o listă, și anterioara / următoarea din ea. --}}
@if ($links)
    <nav class="wsa-back" aria-label="{{ __('Back') }}">
        <x-wsa.arrow to="left" />
        @foreach ($links as $link)
            <a href="{{ $link['url'] }}">{{ $link['label'] }}</a>@unless ($loop->last)<span aria-hidden="true">/</span>@endunless
        @endforeach
        @if ($pager ?? null)
            <span class="wsa-back-pager">
                @if ($pager['prev'])<a href="{{ $pager['prev'] }}" rel="prev" title="{{ __('Previous task') }}"><x-wsa.arrow to="left" />{{ __('Previous one') }}</a>@endif
                @if ($pager['next'])<a href="{{ $pager['next'] }}" rel="next" title="{{ __('Next task') }}">{{ __('Next one') }}<x-wsa.arrow /></a>@endif
            </span>
        @endif
    </nav>
@endif
