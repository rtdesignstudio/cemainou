@props(['url', 'label' => true])
{{-- „Copiază linkul" unei sarcini: adresa ei, de lipit la agent („lucrează la …") sau unui coleg; iconiță cu linie, confirmare scurtă. --}}
<button type="button" {{ $attributes->class(['wsa-copy-task']) }} x-data="{ ok: false }"
    x-on:click.stop.prevent="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($url) }}).then(() => { ok = true; setTimeout(() => ok = false, 1800) })"
    title="{{ __('Copy the link to this task, e.g. for your agent') }}" aria-label="{{ __('Copy the link') }}">
    {{ svg('heroicon-o-link', '', ['aria-hidden' => 'true', 'x-show' => '! ok']) }}
    {{ svg('heroicon-o-check', '', ['aria-hidden' => 'true', 'x-show' => 'ok', 'x-cloak' => true]) }}
    @if ($label)<span x-text="ok ? @js(__('Copied')) : @js(__('Copy the link'))">{{ __('Copy the link') }}</span>@endif
</button>
