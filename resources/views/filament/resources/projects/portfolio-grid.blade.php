{{-- Cardurile proiectelor (din portfolio.blade.php, desenate prin PageCache). --}}
@if ($cards->isEmpty())
    <p class="wsa-empty">{{ $empty }}</p>
@endif
<div class="wsa-portfolio-grid" wire:loading.class="is-loading" wire:target="find,status,clientId,sort">
    @foreach ($cards as $c)
        <x-wsa.pcard :c="$c" :period-label="$periodLabel" :from="$pFrom" :to="$pTo" />
    @endforeach
</div>
