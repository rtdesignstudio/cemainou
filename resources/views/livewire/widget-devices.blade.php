{{-- Profil: unde ai pornit butonul de feedback (site, proiect, dispozitiv, ultima folosire), cu „Oprește" pe fiecare. --}}
<div>
    @if ($devices->isNotEmpty())
        <section class="wsa-block">
            <header class="wsa-block-head"><h2>{{ __('Feedback on sites') }}</h2><span>{{ __('devices where the button is on') }}</span></header>
            @foreach ($devices as $device)
                <div class="wsa-disk-row" wire:key="wd-{{ $device->id }}">
                    <div class="wsa-disk-name"><b>{{ $device->domain }}</b><span class="wsa-meta">{{ $device->project?->name }} · {{ $device->device }} · {{ __('used :time', ['time' => ($device->last_used_at ?? $device->created_at)->diffForHumans()]) }}</span></div>
                    <span class="wsa-disk-action"><button type="button" class="wsa-inline-btn" wire:click="revoke({{ $device->id }})" wire:confirm="{{ __('Turn the Feedback button off on this device?') }}">{{ __('Turn off') }}</button></span>
                </div>
            @endforeach
        </section>
    @endif
</div>
