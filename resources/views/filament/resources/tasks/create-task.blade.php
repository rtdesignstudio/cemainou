<x-filament-panels::page>
    {{-- sarcina nouă: titlul, proiectul, cine o face, detaliile; fișierele ca pe pagina proiectului, apoi „Adaugă sarcina" --}}
    <form wire:submit="add" class="wsa-task-new wsa-task-create">
        {{ $this->form }}
        <label class="wsa-feedback-files">
            <input type="file" wire:model="files" multiple class="sr-only">
            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedPaperClip" class="wsa-feedback-clip" aria-hidden="true" />
            <span>{{ __('Add screenshots, a video or a file') }}</span>
        </label>
        <p class="wsa-meta" wire:loading wire:target="files">{{ __('Uploading…') }}</p>
        @foreach ($files as $i => $file)
            <p class="wsa-feedback-file" wire:key="task-file-{{ $i }}">
                <span>{{ $file->getClientOriginalName() }}</span>
                <button type="button" class="wsa-inline-btn" wire:click="removeFile({{ $i }})">{{ __('Remove') }}</button>
            </p>
        @endforeach
        @error('files') <p class="wsa-error">{{ $message }}</p> @enderror
        @error('files.*') <p class="wsa-error">{{ $message }}</p> @enderror
        <x-filament::button type="submit" class="wsa-feedback-send" wire:target="add,files">{{ __('Add the task') }}</x-filament::button>
    </form>
</x-filament-panels::page>
