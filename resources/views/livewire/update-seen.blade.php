{{-- „Văzut": butonul (cine nu e autorul) și cine l-a citit, cu ochiul cu linie. --}}
<div class="wsa-seen">
    @if ($canAck)
        <button type="button" wire:click="toggle" wire:loading.attr="disabled" @class(['wsa-seen-btn', 'is-on' => $mine]) aria-pressed="{{ $mine ? 'true' : 'false' }}">
            @if ($mine)
                {{ svg('heroicon-o-check', '', ['aria-hidden' => 'true']) }}
                <span>{{ __('Seen') }}</span>
            @else
                {{ svg('heroicon-o-eye', '', ['aria-hidden' => 'true']) }}
                <span>{{ __('Mark as seen') }}</span>
            @endif
        </button>
    @endif
    <p class="wsa-meta">
        @if ($acks->isEmpty())
            {{ __('Nobody marked it as seen yet.') }}
        @else
            {{ __('Seen by') }}
            @foreach ($acks as $ack){{ $loop->first ? ' ' : ', ' }}<a href="{{ \App\Filament\Resources\Users\UserResource::getUrl('view', ['record' => $ack->user]) }}" title="{{ \App\Support\Ago::exact($ack->created_at) }}">{{ $ack->user->name }}</a>@endforeach
        @endif
    </p>
</div>
