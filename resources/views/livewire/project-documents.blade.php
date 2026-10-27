@php($icons = \Filament\Support\Icons\Heroicon::class)
<section class="wsa-block" id="documents" data-wsa-gallery>
    <header class="wsa-block-head">
        <h2>{{ __('Documents') }}</h2>
        <span>{{ $forClient ? __('shared with you, and yours') : __('documents, files and links') }}</span>
    </header>

    @if (! $forClient && ! $documents->contains('is_context', true))
        <p class="wsa-context-hint">{{ __('No project context yet: run /ctx in your agent and it writes one with you (what the project is, how to run and publish it, conventions, decisions), so colleagues start with the whole picture.') }}</p>
    @endif
    @foreach ($documents as $document)
        <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('document', ['record' => $project, 'document' => $document]) }}" class="wsa-doc" wire:key="doc-{{ $document->id }}">
            <x-filament::icon :icon="$icons::OutlinedDocumentText" class="wsa-doc-icon" />
            <span class="wsa-doc-title">{{ $document->title }}@if ($document->is_context) <span class="wsa-tag is-soft">{{ __('Context') }}</span>@endif</span>
            <span class="wsa-doc-meta">
                @if ($document->version)
                    v{{ $document->version }} · {{ $document->editor?->name ?? __('Someone') }} · <x-wsa.time :at="$document->updated_at" />
                @else
                    {{ __('empty') }}
                @endif
                @if ($canShare)
                    @can('share', $document)
                        · <button type="button" class="wsa-inline-btn" wire:click.prevent="share('document', {{ $document->id }})" wire:loading.attr="disabled">{{ $document->client_visible ? __('Visible to the client') : __('Only the team') }}</button>
                    @else
                        @if ($document->client_visible) · {{ __('Visible to the client') }} @endif
                    @endcan
                @endif
            </span>
        </a>
    @endforeach

    @foreach ($attachments as $attachment)
        <div class="wsa-doc" wire:key="att-{{ $attachment->id }}">
            @if ($attachment->isImage())
                <a href="{{ $attachment->href() }}" class="wsa-doc-thumb" data-wsa-lightbox data-caption="{{ $attachment->title }}" aria-label="{{ $attachment->title }}"><img src="{{ $attachment->href() }}" alt="" loading="lazy"></a>
                <a href="{{ $attachment->href() }}" class="wsa-doc-title" data-wsa-lightbox data-caption="{{ $attachment->title }}">{{ $attachment->title }}</a>
            @else
                <x-filament::icon :icon="$attachment->icon()" class="wsa-doc-icon" />
                <a href="{{ $attachment->href() }}" target="_blank" rel="noopener noreferrer" class="wsa-doc-title">{{ $attachment->title }}</a>
            @endif
            <span class="wsa-doc-meta">
                @if ($attachment->role)<span @class(['wsa-role', 'is-demo' => $attachment->role === \App\Enums\AttachmentRole::Demo])>{{ $attachment->role->getLabel() }}</span> · @endif
                {{ $attachment->isFile() ? strtoupper($attachment->extension()).' · '.\App\Support\Files::size($attachment->size) : ($attachment->service() ? $attachment->service().' · ' : '').$attachment->host() }}
                · {{ $attachment->author?->name ?? __('Someone') }}
                @if ($canShare)
                    @can('share', $attachment)
                        · <button type="button" class="wsa-inline-btn" wire:click="share('attachment', {{ $attachment->id }})" wire:loading.attr="disabled">{{ $attachment->client_visible ? __('Visible to the client') : __('Only the team') }}</button>
                    @else
                        @if ($attachment->client_visible) · {{ __('Visible to the client') }} @endif
                    @endcan
                @endif
                @can('delete', $attachment)
                    · <select class="wsa-role-select" wire:change="setRole({{ $attachment->id }}, $event.target.value)" aria-label="{{ __('What it is') }}">
                        <option value="">{{ __('Role') }}</option>
                        @foreach (\App\Enums\AttachmentRole::options() as $value => $label)<option value="{{ $value }}" @selected($attachment->role?->value === $value)>{{ $label }}</option>@endforeach
                    </select>
                    · <button type="button" class="wsa-inline-btn" wire:click="remove({{ $attachment->id }})" wire:loading.attr="disabled" wire:target="remove({{ $attachment->id }})"
                        wire:confirm="{{ $attachment->isFile() ? __('Delete this file? It cannot be recovered.') : __('Remove this link?') }}">{{ __('Delete') }}</button>
                @endcan
            </span>
        </div>
    @endforeach

    @if ($documents->isEmpty() && $attachments->isEmpty())
        <p class="wsa-empty">{{ $forClient ? __('Nothing shared yet. Add a brief, texts, photos or a link for the team: they see it at once.') : __('No documents yet: a brief, a concept, decisions; presentations, PDFs, mockups; links to Figma or Drive. Agents can write them too, when you ask.') }}</p>
    @endif

    <div class="wsa-doc-actions">
        <button type="button" class="wsa-inline-btn {{ $adding === 'document' ? 'is-open' : '' }}" wire:click="open('document')">{{ __('New document') }}</button>
        <button type="button" class="wsa-inline-btn" x-data x-on:click="$dispatch('wsa-capture', { kind: 'file', project: {{ $project->id }} })">{{ __('File or link') }}</button>
        <span class="wsa-meta">{{ __('or drop files anywhere on the page, or paste a screenshot or a link') }}</span>
    </div>

    @if ($adding === 'document')
        <form wire:submit="createDocument" class="wsa-connect wsa-doc-form">
            <input type="text" wire:model="title" maxlength="120" placeholder="{{ __('Title, e.g. Brief or Logo concept') }}" aria-label="{{ __('Document title') }}" autofocus>
            @error('title') <p class="wsa-error">{{ $message }}</p> @enderror
            <x-filament::button type="submit" size="sm" wire:target="createDocument">{{ __('Create and write') }}</x-filament::button>
        </form>
    @endif
</section>
