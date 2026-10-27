{{--
    Uneltele unui om (TeamSetup), ca niște chip-uri: ok discret, ce lipsește gri, ce s-a stricat scos în evidență.
    Un chip cu explicație se apasă (și pe telefon, unde nu e hover): explicația apare dedesubt, pe tot rândul; încă o apăsare o închide.
    $link (opțional): adresa la care duce un chip (la omul însuși: spre pornirea uneltei); $slot: ce mai vine după chip-uri.
--}}
@props(['items', 'link' => null])
<div {{ $attributes->class('wsa-setup') }} x-data="{ tip: null }" @click.outside="tip = null">
    @foreach ($items as $s)
        @php($href = $link ? $link($s) : null)
        @if ($href)
            <a href="{{ $href }}" class="is-{{ $s['state'] }}" title="{{ $s['note'] }}">{{ $s['label'] }}</a>
        @elseif (filled($s['note']))
            <button type="button" class="is-{{ $s['state'] }}" title="{{ $s['note'] }}" x-on:click="tip = tip === @js($s['key']) ? null : @js($s['key'])" x-bind:aria-expanded="tip === @js($s['key'])" x-bind:class="{ 'is-open': tip === @js($s['key']) }">{{ $s['label'] }}</button>
        @else
            <span class="is-{{ $s['state'] }}">{{ $s['label'] }}</span>
        @endif
    @endforeach
    {{ $slot }}
    @foreach ($items as $s)
        @if (filled($s['note']))
            <p class="wsa-setup-tip" x-show="tip === @js($s['key'])" x-cloak><b>{{ $s['label'] }}:</b> {{ $s['note'] }}</p>
        @endif
    @endforeach
</div>
