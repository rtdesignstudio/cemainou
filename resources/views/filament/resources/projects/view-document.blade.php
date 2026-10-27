<x-filament-panels::page>
    <div class="wsa-split wsa-document">
        <div>
            @if ($edit)
                <form wire:submit="save" class="wsa-editor">
                    @if ($conflict)
                        <div class="wsa-conflict">
                            <p><b>{{ __(':name saved version :version meanwhile.', $conflict) }}</b> {{ __('Your text is kept below and nothing was overwritten. Lines marked − are from the saved version, lines marked + are yours: bring its changes into your text, then save.') }}</p>
                            @include('filament.resources.projects.partials.diff', ['hunks' => $conflictDiff])
                        </div>
                    @endif

                    <label class="wsa-editor-label" for="draft-title">{{ __('Title') }}</label>
                    <div class="wsa-connect">
                        <input id="draft-title" type="text" wire:model="draftTitle" maxlength="120">
                    </div>
                    @error('draftTitle') <p class="wsa-error">{{ $message }}</p> @enderror

                    <div class="wsa-editor-tabs" role="tablist">
                        <button type="button" role="tab" @class(['is-active' => ! $preview]) aria-selected="{{ $preview ? 'false' : 'true' }}" wire:click="$set('preview', false)">{{ __('Write') }}</button>
                        <button type="button" role="tab" @class(['is-active' => $preview]) aria-selected="{{ $preview ? 'true' : 'false' }}" wire:click="$set('preview', true)">{{ __('Preview') }}</button>
                        <span class="wsa-meta">{{ __('Markdown: # title, **bold**, - list, - [ ] to do, | table |') }}</span>
                    </div>
                    @if ($preview)
                        <div class="wsa-prose wsa-preview">{{ $html }}</div>
                    @else
                        <textarea wire:model="draftBody" class="wsa-textarea" rows="24" aria-label="{{ __('Text') }}" placeholder="{{ __('Write in Markdown. Every save becomes a version; nothing is lost.') }}"></textarea>
                    @endif
                    @error('draftBody') <p class="wsa-error">{{ $message }}</p> @enderror

                    <div class="wsa-connect wsa-editor-note">
                        <input type="text" wire:model="draftNote" maxlength="200" placeholder="{{ __('What changed (optional), e.g. added the palette') }}" aria-label="{{ __('What changed') }}">
                    </div>
                    <div class="wsa-editor-actions">
                        <x-filament::button type="submit" wire:target="save">{{ $conflict ? __('Save my version') : __('Save') }}</x-filament::button>
                        <x-filament::button color="gray" outlined wire:click="cancel" wire:target="save">{{ __('Cancel') }}</x-filament::button>
                    </div>
                </form>
            @else
                @if ($shown)
                    <div class="wsa-version-bar">
                        <span>{{ __('Version :version of :total', ['version' => $shown->version, 'total' => $document->version]) }} · {{ $shown->author?->name ?? __('Someone') }} · {{ $shown->created_at->setTimezone(auth()->user()->tz())->locale(app()->getLocale())->isoFormat('D MMMM YYYY, HH:mm') }}</span>
                        <button type="button" class="wsa-inline-btn" wire:click="showVersion({{ $document->version }})">{{ __('Back to the current version') }}</button>
                    </div>
                @endif

                @if ($number > 0)
                    <div class="wsa-doc-switch">
                        <button type="button" @class(['is-active' => ! $changes]) wire:click="$set('changes', false)">{{ __('Text') }}</button>
                        <button type="button" @class(['is-active' => $changes]) wire:click="$set('changes', true)">{{ $number > 1 ? __('What changed in version :version', ['version' => $number]) : __('First version') }}</button>
                    </div>
                @endif

                @if ($changes && $diff !== null)
                    @include('filament.resources.projects.partials.diff', ['hunks' => $diff])
                @elseif ($number === 0)
                    <p class="wsa-empty">{{ __('Nothing written yet.') }}</p>
                @else
                    <article class="wsa-prose">{{ $html }}</article>
                @endif
            @endif
        </div>

        @unless (auth()->user()->isClient())                    {{-- istoria versiunilor e a echipei --}}
        <aside class="wsa-aside">
            <section class="wsa-block">
                <header class="wsa-block-head">
                    <h2>{{ __('Versions') }}</h2>
                    <span>{{ __('newest first') }}</span>
                </header>
                @forelse ($versions as $version)
                    <button type="button" wire:key="version-{{ $version->id }}" wire:click="showVersion({{ $version->version }})" @disabled($edit)
                        @class(['wsa-version', 'is-current' => $version->version === $number && ! $edit])>
                        <span class="wsa-version-head">
                            <b>v{{ $version->version }}</b>
                            <span>{{ $version->author?->name ?? __('Someone') }} · <x-wsa.time :at="$version->created_at" />@if ($version->source !== \App\Enums\Source::Web) · {{ $version->source->getLabel() }}@endif</span>
                        </span>
                        @if ($version->note)
                            <span class="wsa-version-note">{{ $version->note }}</span>
                        @endif
                    </button>
                @empty
                    <p class="wsa-empty">{{ __('No versions yet: the first save makes version 1.') }}</p>
                @endforelse
            </section>
        </aside>
        @endunless
    </div>
</x-filament-panels::page>
