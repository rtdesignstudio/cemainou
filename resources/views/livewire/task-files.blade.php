<section class="wsa-block wsa-comments" data-wsa-gallery>
    <header class="wsa-block-head">
        <h2>{{ __('Files') }}</h2>
        <span>{{ __('screenshots, PDFs, links') }}</span>
    </header>

    @foreach ($attachments as $attachment)
        <div class="wsa-doc" wire:key="tatt-{{ $attachment->id }}">
            @if ($attachment->isImage())
                <a href="{{ $attachment->href() }}" class="wsa-doc-thumb" data-wsa-lightbox data-caption="{{ $attachment->title }}" aria-label="{{ $attachment->title }}"><img src="{{ $attachment->href() }}" alt="" loading="lazy"></a>
                <a href="{{ $attachment->href() }}" class="wsa-doc-title" data-wsa-lightbox data-caption="{{ $attachment->title }}">{{ $attachment->title }}</a>
            @else
                <x-filament::icon :icon="$attachment->icon()" class="wsa-doc-icon" />
                <a href="{{ $attachment->href() }}" target="_blank" rel="noopener noreferrer" class="wsa-doc-title">{{ $attachment->title }}</a>
            @endif
            <span class="wsa-doc-meta">
                {{ $attachment->isFile() ? strtoupper($attachment->extension()).' · '.\App\Support\Files::size($attachment->size) : $attachment->host() }}
                · {{ $attachment->author?->name ?? __('Someone') }}
                @can('delete', $attachment)
                    · <button type="button" class="wsa-inline-btn" wire:click="remove({{ $attachment->id }})" wire:confirm="{{ $attachment->isFile() ? __('Delete this file? It cannot be recovered.') : __('Remove this link?') }}">{{ __('Delete') }}</button>
                @endcan
            </span>
        </div>
    @endforeach

    @can('comment', $task)
        <div class="wsa-doc-actions">
            <button type="button" class="wsa-inline-btn {{ $adding === 'file' ? 'is-open' : '' }}" wire:click="open('file')">{{ __('Upload a file') }}</button>
            <button type="button" class="wsa-inline-btn {{ $adding === 'link' ? 'is-open' : '' }}" wire:click="open('link')">{{ __('Add a link') }}</button>
        </div>
        @if ($adding === 'file')
            <form wire:submit="saveFile" class="wsa-connect wsa-doc-form" x-data="{ failed: false }" x-on:livewire-upload-start="failed = false" x-on:livewire-upload-error="failed = true">
                <input type="file" wire:model="file" aria-label="{{ __('File') }}" class="wsa-file">
                <div wire:loading wire:target="file" class="wsa-meta">{{ __('Uploading...') }}</div>
                @error('file') <p class="wsa-error">{{ $message }}</p> @enderror
                <p class="wsa-error" x-cloak x-show="failed">{{ __('The file could not be uploaded. It may be larger than the server accepts (:size MB), or the connection dropped. Try again.', ['size' => \App\Support\Files::phpLimitMb()]) }}</p>
                <input type="text" wire:model="title" maxlength="160" placeholder="{{ __('Title (optional; otherwise the file name)') }}" aria-label="{{ __('Title') }}">
                <x-filament::button type="submit" size="sm" wire:target="saveFile,file">{{ __('Upload') }}</x-filament::button>
            </form>
        @elseif ($adding === 'link')
            <form wire:submit="addLink" class="wsa-connect wsa-doc-form">
                <input type="text" wire:model="title" maxlength="160" placeholder="{{ __('Title, e.g. Figma mockups') }}" aria-label="{{ __('Title') }}">
                @error('title') <p class="wsa-error">{{ $message }}</p> @enderror
                <input type="url" wire:model="url" maxlength="2000" placeholder="https://" aria-label="{{ __('Address') }}">
                @error('url') <p class="wsa-error">{{ $message }}</p> @enderror
                <x-filament::button type="submit" size="sm" wire:target="addLink">{{ __('Add') }}</x-filament::button>
            </form>
        @endif
    @endcan
</section>
