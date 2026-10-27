{{-- Invitațiile încă active ale unui domeniu: cine a dat-o, cât mai ține, câți au intrat, când ultima dată; „Oprește" (cel care a dat-o, liderul, adminul). --}}
<div class="wsa-invites">
    @forelse ($invites as $invite)
        <div class="wsa-invite">
            <div>
                <b>{{ $invite->user?->name }}</b>
                <span class="wsa-meta">
                    {{ $invite->single_use ? __('once') : ($invite->expires_at ? __('until :date', ['date' => $invite->expires_at->copy()->setTimezone(auth()->user()->tz())->locale(app()->getLocale())->isoFormat('D MMM')]) : __('until stopped')) }}
                    · {{ trans_choice(':count opening|:count openings', $invite->uses) }}
                    @if ($invite->last_used_at) · {{ __('opened :time', ['time' => $invite->last_used_at->diffForHumans()]) }}@endif
                </span>
                <x-wsa.copy-link :url="$invite->url()" />
            </div>
            @if ($invite->user_id === auth()->id() || $leads)
                <button type="button" class="wsa-inline-btn" wire:click="revokeInvite({{ $invite->id }})" wire:confirm="{{ __('Stop this invitation? The link stops working and the phones that came through it can no longer send.') }}">{{ __('Stop') }}</button>
            @endif
        </div>
    @empty
        <p class="wsa-empty">{{ __('No active invitations. Make one with “Invite to give feedback”.') }}</p>
    @endforelse
</div>
