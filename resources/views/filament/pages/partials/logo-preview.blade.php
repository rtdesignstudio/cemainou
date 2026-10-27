{{-- Setări → Companie: logo-ul de acum, pe alb, la mărimea de pe Azi și de la intrare --}}
@if ($logo)
    <div class="wsa-logo-preview">
        <span class="wsa-label">{{ __('Logo') }}</span>
        <div class="wsa-logo-preview-box">
            <img src="{{ $logo }}" alt="{{ $name ?? __('Logo') }}">
        </div>
        <span class="wsa-meta">{{ __('As it shows on Today and on the sign-in page.') }}</span>
    </div>
@endif
