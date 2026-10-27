{{-- „Susține cemainou": o dată pe lună, doar adminilor unei echipe de peste 5 oameni; spune ce a făcut echipa, nu cere nimic obligatoriu --}}
<x-filament-widgets::widget>
    @if (! \App\Models\Dismissal::activeFor(auth()->user())->has('support'))
        <div class="wsa-hint wsa-support">
            <span>{{ __('Your team of :people wrote :updates updates and finished :done tasks in cemainou in the last 30 days. It is free and stays free; if it helps you, support it.', ['people' => $people, 'updates' => $updates, 'done' => $done]) }}</span>
            <span class="wsa-support-acts">
                <a href="{{ $url }}" target="_blank" rel="noopener" class="wsa-hint-link">{{ __('Support cemainou') }}<x-wsa.arrow /></a>
                <button type="button" class="wsa-inline-btn" wire:click="later">{{ __('Not now') }}</button>
            </span>
        </div>
    @endif
</x-filament-widgets::widget>
