{{-- Acțiunile unui rând „Blocat" / „Urmează": Rezolvat (cu notă), Fă sarcină, Comentează; rezolvat: cine și ce, cu „Redeschide" --}}
<span class="wsa-item-actions">
    @if ($item->resolved_at)
        <span class="wsa-meta wsa-resolved">{{ __('resolved by :name', ['name' => $item->resolver?->name ?? __('someone')]) }} · <x-wsa.time :at="$item->resolved_at" />@if ($item->resolution) · {{ $item->resolution }}@endif</span>
        <button type="button" class="wsa-inline-btn" wire:click="reopen">{{ __('Reopen') }}</button>
    @elseif ($mode === 'resolve')
        <span class="wsa-item-form">
            <input type="text" wire:model="note" wire:keydown.enter="resolve" wire:keydown.escape="open('')" maxlength="300" placeholder="{{ __('How it was solved (optional)') }}" aria-label="{{ __('How it was solved (optional)') }}" x-init="$el.focus()">
            <button type="button" class="wsa-inline-btn is-strong" wire:click="resolve">{{ __('Resolved') }}</button>
            <button type="button" class="wsa-inline-btn" wire:click="open('')">{{ __('Cancel') }}</button>
        </span>
    @elseif ($mode === 'comment')
        <span class="wsa-item-form is-wide">
            <textarea wire:model="note" rows="2" maxlength="5000" placeholder="{{ __('Your comment') }}" aria-label="{{ __('Your comment') }}" x-init="$el.focus()"></textarea>
            <button type="button" class="wsa-inline-btn is-strong" wire:click="comment">{{ __('Comment') }}</button>
            <button type="button" class="wsa-inline-btn" wire:click="open('')">{{ __('Cancel') }}</button>
        </span>
    @elseif ($item->isOpen())
        <button type="button" class="wsa-inline-btn" wire:click="open('resolve')">{{ __('Resolved') }}</button>
        @if ($canTask)<button type="button" class="wsa-inline-btn" wire:click="makeTask" wire:loading.attr="disabled">{{ __('Make it a task') }}</button>@endif
        @unless ($compact)<button type="button" class="wsa-inline-btn" wire:click="open('comment')">{{ __('Comment') }}</button>@endunless
    @endif
    @error('note')<span class="wsa-meta">{{ $message }}</span>@enderror
</span>
