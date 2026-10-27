<x-filament-panels::page>
    {{-- ce a venit din Share: imaginile (previzualizare), felul (o captură pornește ca sarcină pentru tine), textul (opțional cu o imagine), proiectul (cele recente, la o atingere); apoi un buton --}}
    @if (! $shared)
        <p class="wsa-empty">{{ __('Nothing waiting from Share. Share a screenshot, a link or a text to cemainou from any app on your phone.') }}</p>
    @else
        <form wire:submit="save" class="wsa-capture-form wsa-shared">
            @php($images = collect($shared['files'] ?? [])->filter(fn ($f) => str_starts_with((string) ($f['mime'] ?? ''), 'image/')))
            @if ($images->isNotEmpty())
                <div class="wsa-shared-shots">
                    @foreach ($shared['files'] as $i => $file)
                        @if (str_starts_with((string) ($file['mime'] ?? ''), 'image/'))<img src="{{ route('share.file', ['id' => $id, 'index' => $i]) }}" alt="{{ $file['name'] }}">@endif
                    @endforeach
                </div>
            @endif
            <div class="wsa-capture-kinds" role="radiogroup" aria-label="{{ __('What is it') }}">
                @foreach (['task' => __('Task'), 'idea' => __('Idea'), 'note' => __('Note')] as $value => $label)
                    <button type="button" role="radio" aria-checked="{{ $kind === $value ? 'true' : 'false' }}" wire:click="$set('kind', '{{ $value }}')" @class(['is-on' => $kind === $value])>{{ $label }}</button>
                @endforeach
            </div>
            <textarea wire:model="text" rows="4" maxlength="{{ \App\Models\Task::MAX_NOTES + 200 }}" aria-label="{{ __('Text') }}" placeholder="{{ $images->isNotEmpty() ? __('What should change? Optional: the first line is the title.') : __('What is on your mind? The first line is the title.') }}"></textarea>
            @error('text') <p class="wsa-error">{{ $message }}</p> @enderror

            <div class="wsa-label">{{ __('Project') }}</div>
            @if ($recent->isNotEmpty())
                <div class="wsa-mine-people wsa-shared-projects">
                    @foreach ($recent as $pid)
                        <button type="button" @class(['is-on' => $project === $pid]) aria-pressed="{{ ($project === $pid) ? 'true' : 'false' }}" wire:click="$set('project', {{ $pid }})">{{ $projects[$pid] }}</button>
                    @endforeach
                </div>
            @endif
            <div class="wsa-capture-row">
                <select wire:model.live="project" aria-label="{{ __('Another project') }}">
                    @foreach ($projects as $pid => $name)
                        <option value="{{ $pid }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            @if ($kind === 'task')
                <label class="wsa-layer-group"><input type="checkbox" wire:model.live="mine"> {{ __('For me') }}</label>
            @endif
            @if (count($shared['files'] ?? []) > $images->count())
                <div class="wsa-label">{{ trans_choice(':count file comes along|:count files come along', count($shared['files']) - $images->count()) }}</div>
                @foreach ($shared['files'] as $file)
                    @unless (str_starts_with((string) ($file['mime'] ?? ''), 'image/'))<p class="wsa-feedback-file"><span>{{ $file['name'] }}</span><span class="wsa-meta">{{ \App\Support\Files::size($file['size'] ?? null) }}</span></p>@endunless
                @endforeach
            @endif
            <x-filament::button type="submit" class="wsa-feedback-send" wire:target="save">{{ match ($kind) { 'task' => __('Add the task'), 'note' => __('Save the note'), default => __('Save the idea') } }}</x-filament::button>
        </form>
    @endif
</x-filament-panels::page>
