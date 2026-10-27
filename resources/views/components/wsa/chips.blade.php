{{--
    Un rând de filtre la vedere, ca la Sarcini (oamenii, proiectele, clienții): „Toate" + câte un buton; alegerea scrie în proprietatea Livewire $model.
    $items: [id => nume] sau [id => ['name' => ..., 'count' => ..., 'note' => ...]].
--}}
@props(['items', 'model', 'all', 'current' => null, 'label' => null])
@php($items = collect($items))
@if ($items->count() > 1 || $current)
    <nav class="wsa-mine-people wsa-layer" aria-label="{{ $label ?? $all }}">
        <button type="button" @class(['is-on' => ! $current]) aria-pressed="{{ (! $current) ? 'true' : 'false' }}" wire:click="$set('{{ $model }}', null)">{{ $all }}</button>
        @foreach ($items as $id => $item)
            @php($item = is_array($item) ? $item : ['name' => $item])
            <button type="button" @class(['is-on' => (string) $current === (string) $id]) aria-pressed="{{ ((string) $current === (string) $id) ? 'true' : 'false' }}" wire:click="$set('{{ $model }}', {{ (int) $id }})">
                {{ $item['name'] }}@if (! empty($item['note']))<small>{{ $item['note'] }}</small>@endif @if (isset($item['count']))<span>{{ $item['count'] }}</span>@endif
            </button>
        @endforeach
    </nav>
@endif
