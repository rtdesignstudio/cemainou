{{-- logo-ul companiei (Setări → Companie): pe Azi în dreapta titlului; la intrare, deasupra formularului --}}
@if ($logo = \App\Support\Branding::logoUrl())
    <img src="{{ $logo }}" alt="{{ \App\Support\Branding::name() ?? __('Logo') }}" class="{{ $class ?? 'wsa-company-logo' }}">
@endif
