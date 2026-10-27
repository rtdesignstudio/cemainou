{{-- Linkul, scurtat, cu „Copiază linkul" alături (la QR-urile widget-ului: același link, de trimis sau de deschis pe alt dispozitiv). --}}
@props(['url'])
<div class="wsa-copy-link" x-data="{ copied: false }">
    <span class="wsa-copy-link-url" title="{{ $url }}">{{ preg_replace('#^https?://#', '', $url) }}</span>
    <button type="button" class="wsa-copy-link-btn"
        x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($url) }}).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
        <span x-show="! copied">{{ __('Copy the link') }}</span><span x-show="copied" x-cloak>{{ __('Copied') }}</span>
    </button>
</div>
