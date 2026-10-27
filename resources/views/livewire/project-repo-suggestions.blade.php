<div>
    @if ($suggestions->isNotEmpty())
        <section class="wsa-drafts wsa-suggestions">
            <header class="wsa-drafts-head">
                <h2>{{ __('Repositories to link') }}</h2>
                <span>{{ __('colleagues worked from them on this project') }}</span>
            </header>
            @foreach ($suggestions as $repo)
                <div class="wsa-draft" wire:key="repo-{{ $repo->id }}">
                    <div class="wsa-draft-main">
                        <div class="wsa-row-text">{{ $repo->repo_key }}</div>
                        <div class="wsa-meta">{{ __('suggested by :name', ['name' => $repo->suggester?->name ?? __('Someone')]) }} · <x-wsa.time :at="$repo->created_at" /></div>
                    </div>
                    <button type="button" class="wsa-link" wire:click="dismiss({{ $repo->id }})" wire:loading.attr="disabled" wire:target="dismiss({{ $repo->id }})" wire:confirm="{{ __('Dismiss this repository? It will not be suggested again.') }}">{{ __('Dismiss') }}</button>
                    <x-filament::button wire:click="link({{ $repo->id }})">{{ __('Link') }}</x-filament::button>
                </div>
            @endforeach
        </section>
    @endif
</div>
