{{-- Sarcini: cifrele de sus, pe selecția de deasupra (omul, proiectul); fiecare duce la lista ei (deschise, ale mele, depășite, blocate, gata săptămâna asta). --}}
@php($url = fn (string $tab, ?string $show = null) => \App\Filament\Resources\Tasks\TaskResource::getUrl('index', array_filter(['tab' => $tab, 'show' => $show, 'person' => $this->person, 'project' => $this->projectPick])))
<x-wsa.stats class="wsa-tasks-stats">
    <x-wsa.stat :label="__('Open')" :href="$url('open')">{{ $s['open'] }}</x-wsa.stat>
    @if (! auth()->user()->isClient() && $s['mine'] !== null)
        <x-wsa.stat :label="__('Mine')" :href="$url('mine')">{{ $s['mine'] }}</x-wsa.stat>
    @endif
    <x-wsa.stat :label="__('Overdue')" :href="$url('open', 'overdue')">{{ $s['overdue'] }}</x-wsa.stat>
    <x-wsa.stat :label="__('Blocked tasks')" :blocked="$s['blocked'] > 0" :href="$url('open', 'blocked')">{{ $s['blocked'] }}</x-wsa.stat>
    <x-wsa.stat :label="__('Done this week')" :href="$url('done', 'week')">{{ $s['done'] }}</x-wsa.stat>
</x-wsa.stats>
