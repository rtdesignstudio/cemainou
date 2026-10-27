{{-- Profil → Telegram: legarea contului cu botul echipei (notificările în privat, răspunsurile devin comentarii) --}}
<div>
    @if ($available)
        <section class="wsa-block wsa-telegram" @if ($waiting) wire:poll.4s @endif>
            <header class="wsa-block-head"><h2>Telegram</h2><span>{{ __('in a private chat with the team\'s bot') }}</span></header>
            @if ($user->telegram_chat_id)
                <p>{{ __('Connected as :name. Your notifications come there too; reply to one to comment on that task or update.', ['name' => $user->telegram_name ?: __('your Telegram account')]) }}</p>
                <p><button type="button" class="wsa-inline-btn" wire:click="disconnect" wire:confirm="{{ __('Disconnect Telegram?') }}">{{ __('Disconnect') }}</button></p>
            @else
                <p>{{ __('Get your notifications in Telegram as well, and answer them from there: a reply becomes a comment.') }}</p>
                <div><x-filament::button type="button" wire:click="connect" outlined>{{ __('Connect Telegram') }}</x-filament::button></div>
                @if ($waiting)<p class="wsa-meta">{{ __('Press Start in Telegram, then come back here.') }}</p>@endif
                @error('telegram')<p class="wsa-meta">{{ $message }}</p>@enderror
            @endif
        </section>
    @endif
</div>
