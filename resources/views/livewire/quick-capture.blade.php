{{--
    Notarea rapidă: un eveniment „wsa-capture" (butoanele de pe Azi, „+" din bara de jos, tasta N) o deschide, cu felul cerut sau, din „+", cu alegerea.
    Pagina unui proiect își trimite proiectul, pagina unui om pe omul acela: acolo „+" deschide direct o sarcină (proiectul ei, respectiv pentru el).
    Altfel proiectul rămâne ultimul la care ai lucrat.
    Fișier sau link: pe pagina unui proiect sau a unei sarcini, fișierele trase oriunde pe pagină și ce lipești (o captură, un link) deschid
    direct fereastra, cu ele în ea; acolo alegi titlul, rolul și proiectul, apoi „Adaugă".
--}}
<div x-data="{
        dragging: false,
        uploading: false,
        page() {
            const q = /^\/tasks\/?$/.test(location.pathname) ? new URLSearchParams(location.search) : null;   // pe Sarcini: proiectul și omul aleși deasupra
            return {
                project: location.pathname.match(/^\/projects\/(\d+)/)?.[1] ?? q?.get('project') ?? null,
                task: location.pathname.match(/^\/tasks\/(\d+)/)?.[1] ?? null,
                person: location.pathname.match(/^\/users\/(\d+)(\/)?$/)?.[1] ?? q?.get('person') ?? null,
            };
        },
        start(detail) {
            const { project, task, person } = this.page();
            const where = task ? { task } : { ...(project ? { project } : {}), ...(person ? { assignee: person } : {}) };
            const kind = detail?.kind ?? (person ? 'task' : undefined);   // pe pagina unui proiect: alegerea (idee, sarcină, notiță, fișier, timp), cu proiectul deja ales
            return $wire.open({ ...where, ...detail, ...(kind ? { kind } : {}) }).then(() => {
                $dispatch('open-modal', { id: 'wsa-capture' });
                setTimeout(() => ($root.querySelector('[data-capture-focus]') ?? $root.querySelector('textarea'))?.focus(), 150);
            });
        },
        upload(files) {
            if (! files?.length) return;
            this.uploading = true;
            $wire.uploadMultiple('incoming', [...files], () => { this.uploading = false }, () => { this.uploading = false }, () => {}, () => { this.uploading = false });
        },
        // fișiere trase oriunde pe pagina unui proiect sau a unei sarcini: fereastra se deschide cu ele
        dropOnPage(e) {
            this.dragging = false;
            const { project, task } = this.page();
            if (! (project || task) || ! e.dataTransfer?.files?.length) return;
            e.preventDefault();
            const files = e.dataTransfer.files;
            this.start({ kind: 'file' }).then(() => this.upload(files));
        },
        // lipit pe pagină (nu într-un câmp): o captură sau un link
        pasteOnPage(e) {
            const { project, task } = this.page();
            const target = document.activeElement;
            if (! (project || task) || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName) || target?.isContentEditable || document.querySelector('.fi-modal-open')) return;
            const files = e.clipboardData?.files;
            const text = (e.clipboardData?.getData('text') || '').trim();
            if (files?.length) { e.preventDefault(); this.start({ kind: 'file' }).then(() => this.upload(files)); }
            else if (/^https?:\/\/\S+$/i.test(text)) { e.preventDefault(); this.start({ kind: 'file', url: text }); }
        },
        // lipit în fereastră: capturile se încarcă
        pasteInModal(e) {
            const files = e.clipboardData?.files;
            if ($wire.kind === 'file' && files?.length) { e.preventDefault(); this.upload(files); }
        },
        isFileDrag(e) { return [...(e.dataTransfer?.types || [])].includes('Files'); },
    }"
    x-on:wsa-capture.window="start($event.detail || {})"
    x-init="const fromUrl = () => { const k = (location.hash.match(/^#capture=(\w+)/) || [])[1]; if (! k) return; history.replaceState(null, '', location.pathname + location.search); setTimeout(() => window.dispatchEvent(new CustomEvent('wsa-capture', { detail: { kind: k } })), 0); }; fromUrl(); addEventListener('hashchange', fromUrl); document.addEventListener('livewire:navigated', () => setTimeout(fromUrl, 50))"
    x-on:dragover.window="const p = page(); if ((p.project || p.task) && isFileDrag($event)) { $event.preventDefault(); dragging = true }"
    x-on:dragleave.window="if (! $event.relatedTarget) dragging = false"
    x-on:drop.window="if ($event.target.closest?.('.wsa-capture-drop, input[type=file]')) { dragging = false; return } dropOnPage($event)"
    x-on:paste.window="pasteOnPage($event)"
    x-on:keydown.window="if ($event.key === 'n' && ! $event.metaKey && ! $event.ctrlKey && ! $event.altKey && ! ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) && ! document.activeElement?.isContentEditable) { $event.preventDefault(); start({ kind: 'idea' }) }">
    {{-- tras peste pagina unui proiect sau a unei sarcini: unde ajung fișierele --}}
    <div class="wsa-drop-overlay" x-cloak x-show="dragging" x-transition.opacity aria-hidden="true">
        <div><x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowUpTray" class="wsa-drop-icon" /><b>{{ __('Drop the files here') }}</b><span>{{ __('they go onto this page; you choose the title and the role') }}</span></div>
    </div>

    <button type="button" class="wsa-fab wsa-fab-add" x-on:click="start({})" title="{{ __('Add') }}">
        {{ svg('heroicon-o-plus', 'wsa-fab-icon', ['aria-hidden' => 'true']) }}
        <span class="sr-only">{{ __('Add') }}</span>
    </button>

    <x-filament::modal id="wsa-capture" :width="$choosing ? 'xl' : 'lg'" :heading="$choosing ? __('Add') : match ($kind) { 'task' => __('New task'), 'note' => __('Note for the team'), 'file' => __('File or link'), 'time' => __('Time worked'), default => __('New idea') }">
        @if ($choosing)
            {{-- din „+": ce adaugi - trei plăci mari, apoi rânduri pentru restul, feedback-ul aparte, jos --}}
            <div class="wsa-capture-menu">
            @if ($onProject)
                <p class="wsa-capture-on">{{ __('On :project', ['project' => $onProject]) }}</p>
            @endif
            <div class="wsa-capture-choices">
                <button type="button" wire:click="pick('idea')">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedLightBulb" class="wsa-capture-icon" />
                    <b>{{ __('Idea') }}</b><span>{{ __('for later, without a person') }}</span>
                    <kbd class="wsa-capture-key" title="{{ __('Press N anywhere') }}">N</kbd>
                </button>
                <button type="button" wire:click="pick('task')">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedCheckCircle" class="wsa-capture-icon" />
                    <b>{{ __('Task') }}</b><span>{{ $isClient ? __('a request; it goes to the project lead') : __('to do, for you or anyone') }}</span>
                </button>
                <button type="button" wire:click="pick('note')">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedBookOpen" class="wsa-capture-icon" />
                    <b>{{ __('Note') }}</b><span>{{ __('what colleagues should know') }}</span>
                </button>
                @unless ($isClient)                                     {{-- timpul e al echipei: clientului nu i se măsoară --}}
                    <button type="button" wire:click="pick('time')">
                        <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedClock" class="wsa-capture-icon" />
                        <b>{{ __('Time') }}</b><span>{{ __('a meeting, a call, work the agent did not see') }}</span>
                    </button>
                @endunless
                <button type="button" wire:click="pick('file')">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedPaperClip" class="wsa-capture-icon" />
                    <b>{{ __('File or link') }}</b><span>{{ __('a demo, mockups, a brief, papers') }}</span>
                </button>
            </div>
            <div class="wsa-capture-more">
                <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('create') }}"><x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedPaperClip" class="wsa-capture-icon is-small" /><span><b>{{ __('Task with files') }}</b><span>{{ __('screenshots, a video, documents') }}</span></span><x-wsa.arrow /></a>
                @if ($canProject)<a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('create') }}"><x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedFolderPlus" class="wsa-capture-icon is-small" /><span><b>{{ __('New project') }}</b><span>{{ __('for a client or internal') }}</span></span><x-wsa.arrow /></a>@endif
                @if ($canClient)<a href="{{ \App\Filament\Resources\Clients\ClientResource::getUrl('create') }}"><x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedBuildingOffice" class="wsa-capture-icon is-small" /><span><b>{{ __('New client') }}</b><span>{{ __('the company you work for') }}</span></span><x-wsa.arrow /></a>@endif
            </div>
            @if ($canFeedback)
                {{-- feedback despre cemainou: un compartiment aparte, sub celelalte --}}
                <button type="button" class="wsa-capture-feedback" x-on:click="$dispatch('close-modal', { id: 'wsa-capture' }); setTimeout(() => $dispatch('open-modal', { id: 'wsa-feedback' }), 150)">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedChatBubbleLeftEllipsis" class="wsa-capture-icon is-small" />
                    <span><b>{{ __('Feedback about cemainou') }}</b><span>{{ __('what works badly, what is missing; only the admins see it') }}</span></span>
                </button>
            @endif
            </div>
        @else
            <form wire:submit="save" class="wsa-capture-form">
                <div class="wsa-capture-kinds" role="radiogroup" aria-label="{{ __('What is it') }}">
                    @foreach (['idea' => __('Idea'), 'task' => __('Task'), 'note' => __('Note'), 'file' => __('File'), 'time' => __('Time')] as $value => $label)
                        @continue($value === 'time' && $isClient)
                        <button type="button" role="radio" aria-checked="{{ $kind === $value ? 'true' : 'false' }}" wire:click="$set('kind', '{{ $value }}')" @class(['is-on' => $kind === $value])>{{ $label }}</button>
                    @endforeach
                </div>
                @if ($kind === 'time')
                    {{-- timpul pe care agentul nu l-a văzut: cât (liber sau din butoane), proiectul, ziua, felul, ce a fost --}}
                    <div class="wsa-capture-time">
                        <input type="text" wire:model="duration" data-capture-focus class="wsa-capture-input wsa-capture-duration" placeholder="{{ __('How long, e.g. 1h 30m') }}" aria-label="{{ __('How long') }}" inputmode="text" autocomplete="off">
                        <div class="wsa-capture-chips" role="group" aria-label="{{ __('Quick durations') }}">
                            @foreach (['30m' => '30m', '1h' => '1h', '2h' => '2h', '4h' => '4h', '6h' => '6h', '8h' => '8h'] as $value => $label)
                                <button type="button" wire:click="$set('duration', '{{ $value }}')" @class(['is-on' => $duration === $value]) aria-pressed="{{ ($duration === $value) ? 'true' : 'false' }}">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                    @error('duration') <p class="wsa-error">{{ $message }}</p> @enderror
                    <input type="text" wire:model="note" maxlength="200" class="wsa-capture-input" placeholder="{{ __('What it was, e.g. workshop with the client') }}" aria-label="{{ __('What it was') }}">
                    <div class="wsa-capture-fields is-task">
                        <label class="wsa-capture-field">
                            <span class="wsa-label">{{ __('Project') }}</span>
                            <select wire:model="project">
                                @foreach ($projects as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="wsa-capture-field">
                            <span class="wsa-label">{{ __('Day') }}</span>
                            <input type="date" wire:model="day" min="{{ $oldestDay }}" max="{{ $today }}">
                        </label>
                        <label class="wsa-capture-field">
                            <span class="wsa-label">{{ __('From (optional)') }}</span>
                            <input type="time" wire:model="start" step="300">
                        </label>
                        <label class="wsa-capture-field">
                            <span class="wsa-label">{{ __('Kind of work') }}</span>
                            <select wire:model="category">
                                <option value="">{{ __('Not set') }}</option>
                                @foreach ($categories as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    @foreach (['project', 'day', 'start', 'category', 'note'] as $field)
                        @error($field) <p class="wsa-error">{{ $message }}</p> @enderror
                    @endforeach
                    <p class="wsa-meta">{{ __('Kept apart from the time measured from agent sessions; it goes into the Hours and the reports.') }}</p>
                    <x-filament::button type="submit" class="wsa-feedback-send" wire:target="save">{{ __('Add the time') }}</x-filament::button>
                @elseif ($kind === 'file')
                    {{-- un singur loc: tragi fișiere, lipești o captură, alegi din calculator sau lipești un link --}}
                    <div class="wsa-capture-drop" x-on:dragover.prevent="$el.classList.add('is-over')" x-on:dragleave="$el.classList.remove('is-over')"
                        x-on:drop.prevent="$el.classList.remove('is-over'); upload($event.dataTransfer.files)" x-on:paste="pasteInModal($event)">
                        <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowUpTray" class="wsa-capture-drop-icon" />
                        <p><b>{{ __('Drop files here') }}</b> {{ __('or paste a screenshot') }} · <label class="wsa-link-strong">{{ __('choose from the computer') }}<input type="file" multiple class="sr-only" x-on:change="upload($event.target.files); $event.target.value = ''"></label></p>
                        <span class="wsa-meta">{{ __('Up to :size MB each. Images, PDF, HTML, video, Office and design files.', ['size' => $maxMb]) }}</span>
                        <div class="wsa-meta" x-show="uploading" x-cloak>{{ __('Uploading...') }}</div>
                    </div>
                    @if ($uploads)
                        <ul class="wsa-capture-files">
                            @foreach ($uploads as $i => $file)
                                <li wire:key="up-{{ $i }}"><span>{{ $file->getClientOriginalName() }}</span><span class="wsa-meta">{{ \App\Support\Files::size($file->getSize()) }}</span>
                                    <button type="button" class="wsa-inline-btn" wire:click="removeUpload({{ $i }})" aria-label="{{ __('Remove') }}">{{ __('Remove') }}</button></li>
                            @endforeach
                        </ul>
                    @endif
                    @error('uploads') <p class="wsa-error">{{ $message }}</p> @enderror
                    @error('uploads.*') <p class="wsa-error">{{ $message }}</p> @enderror
                    <input type="url" wire:model="url" data-capture-focus maxlength="2000" class="wsa-capture-input" placeholder="{{ __('Or paste a link: a demo, Figma, Google Drive, Loom') }}" aria-label="{{ __('Link') }}" x-on:paste="pasteInModal($event)">
                    @error('url') <p class="wsa-error">{{ $message }}</p> @enderror
                    <input type="text" wire:model="title" maxlength="160" class="wsa-capture-input" placeholder="{{ __('Title (optional: the file name or the page title)') }}" aria-label="{{ __('Title') }}">
                    <div class="wsa-capture-fields is-task">
                        <label class="wsa-capture-field">
                            <span class="wsa-label">{{ __('Project') }}</span>
                            <select wire:model.live="project" @disabled($task)>
                                @foreach ($projects as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="wsa-capture-field">
                            <span class="wsa-label">{{ __('What it is') }}</span>
                            <select wire:model="role">
                                <option value="">{{ __('Not set') }}</option>
                                @foreach ($roles as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    @if ($taskTitle)
                        <p class="wsa-meta">{{ __('On the task «:title».', ['title' => $taskTitle]) }} <button type="button" class="wsa-inline-btn" wire:click="$set('task', null)">{{ __('Put it on the project instead') }}</button></p>
                    @endif
                    <p class="wsa-meta">{{ __('A demo stays pinned at the top of the project and on its card.') }}</p>
                    <x-filament::button type="submit" class="wsa-feedback-send" wire:target="save,uploads" x-bind:disabled="uploading">{{ __('Add') }}</x-filament::button>
                @else
                <textarea wire:model="text" rows="4" maxlength="{{ \App\Models\Task::MAX_NOTES + 200 }}" aria-label="{{ __('Text') }}"
                    placeholder="{{ match ($kind) { 'task' => __('What is to be done? The first line is the title; details below.'), 'note' => __('E.g. staging is reachable only through the office VPN'), default => __('What is on your mind? The first line is the title.') } }}"
                    x-on:keydown.meta.enter="$wire.save()" x-on:keydown.ctrl.enter="$wire.save()"></textarea>
                @error('text') <p class="wsa-error">{{ $message }}</p> @enderror
                {{-- sarcina: toate câmpurile de pe „Sarcină nouă" (proiect, cine o face, termen, recurență); ideea și notița: doar proiectul --}}
                <div @class(['wsa-capture-fields', 'is-task' => $kind === 'task' && ! $isClient])>
                    <label class="wsa-capture-field">
                        <span class="wsa-label">{{ __('Project') }}</span>
                        <select wire:model.live="project">
                            @foreach ($projects as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if ($kind === 'task' && ! $isClient)
                        <label class="wsa-capture-field">
                            <span class="wsa-label">{{ __('Who does it') }}</span>
                            <select wire:model="assignee">
                                <option value="">{{ __('Free') }}</option>
                                @foreach ($people as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="wsa-capture-field">
                            <span class="wsa-label">{{ __('Due') }}</span>
                            <input type="date" wire:model="due" min="{{ $today }}">
                        </label>
                        <label class="wsa-capture-field">
                            <span class="wsa-label">{{ __('Repeats') }}</span>
                            <select wire:model="repeat">
                                <option value="">{{ __('Does not repeat') }}</option>
                                @foreach ($repeats as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                </div>
                @foreach (['project', 'assignee', 'due', 'repeat'] as $field)
                    @error($field) <p class="wsa-error">{{ $message }}</p> @enderror
                @endforeach
                <x-filament::button type="submit" class="wsa-feedback-send" wire:target="save">{{ match ($kind) { 'task' => __('Add the task'), 'note' => __('Save the note'), default => __('Save the idea') } }}</x-filament::button>
                <p class="wsa-meta wsa-capture-hint">{{ __('Tip: press N anywhere to note an idea; ⌘ / Ctrl + Enter saves.') }}</p>
                @endif
            </form>
        @endif
    </x-filament::modal>
</div>
