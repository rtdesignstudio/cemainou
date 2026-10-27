{{-- Feedback despre cemainou: fereastra (se deschide din alegerea de la „+"). Pagina, fereastra și ecranul se trimit singure. --}}
<div x-data="{
    uploading: false,
    context() {
        return { url: location.href, viewport: innerWidth + '×' + innerHeight, screen: screen.width + '×' + screen.height + ' @' + (Math.round(devicePixelRatio * 100) / 100) + 'x' };
    },
    // capturile lipite (Cmd+V) sau trase în fereastră se adaugă la cele de dinainte
    add(files) {
        if (! files?.length) return;
        this.uploading = true;
        $wire.uploadMultiple('incoming', [...files], () => { this.uploading = false }, () => { this.uploading = false }, () => {}, () => { this.uploading = false });
    },
}">

    <x-filament::modal id="wsa-feedback" width="lg" :heading="__('Feedback about cemainou')" :description="__('What works badly, what is missing, what could be clearer. Only the admins see it.')">
        <form x-on:submit.prevent="$wire.send(context())" class="wsa-feedback-form"
            x-on:paste="if ($event.clipboardData?.files?.length) { $event.preventDefault(); add($event.clipboardData.files) }"
            x-on:dragover.prevent x-on:drop.prevent="add($event.dataTransfer.files)">
            {{ $this->form }}

            <label class="wsa-feedback-files">
                <input type="file" multiple accept="image/*,video/*,application/pdf,text/plain" class="sr-only" x-on:change="add($event.target.files); $event.target.value = ''">
                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedPaperClip" class="wsa-feedback-clip" aria-hidden="true" />
                <span>{{ __('Add screenshots, a video or a PDF') }} <span class="wsa-meta">{{ __('or paste a screenshot (⌘ / Ctrl + V)') }}</span></span>
            </label>
            <p class="wsa-meta" x-show="uploading" x-cloak>{{ __('Uploading…') }}</p>
            @foreach ($files as $i => $file)
                <p class="wsa-feedback-file" wire:key="feedback-file-{{ $i }}">
                    <span>{{ $file->getClientOriginalName() }}</span>
                    <button type="button" class="wsa-inline-btn" wire:click="removeFile({{ $i }})">{{ __('Remove') }}</button>
                </p>
            @endforeach
            @error('files') <p class="wsa-error">{{ $message }}</p> @enderror
            @error('files.*') <p class="wsa-error">{{ $message }}</p> @enderror

            <p class="wsa-meta">{{ __('Sent along: this page\'s link, your screen size and browser.') }}</p>
            <x-filament::button type="submit" class="wsa-feedback-send" wire:target="send,files">{{ __('Send feedback') }}</x-filament::button>
        </form>
    </x-filament::modal>
</div>
