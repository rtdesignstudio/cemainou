{{-- Cifrele portofoliului (din portfolio.blade.php, desenate prin PageCache). --}}
<x-wsa.stats>
    <x-wsa.stat :label="__('Active projects')">{{ $summary['active'] }}</x-wsa.stat>
    @if ($summary['period'] !== null)
        <x-wsa.stat :label="__('Time · :period', ['period' => $periodLabel])"><x-wsa.duration :minutes="$summary['period']" /></x-wsa.stat>
    @endif
    <x-wsa.stat :label="__('Blocked ones')" :blocked="$summary['blocked'] > 0">{{ $summary['blocked'] }}</x-wsa.stat>
    <x-wsa.stat :label="__('Quiet')">{{ $summary['quiet'] }}</x-wsa.stat>
</x-wsa.stats>
