@props(['to' => 'right'])
{{-- Săgeata unui link (înainte / înapoi): o iconiță SVG cu linie, nu un caracter de text. --}}
<x-filament::icon :icon="$to === 'left' ? \Filament\Support\Icons\Heroicon::OutlinedArrowLeft : \Filament\Support\Icons\Heroicon::OutlinedArrowRight" @class(['wsa-arrow', 'wsa-arrow-left' => $to === 'left']) aria-hidden="true" />
