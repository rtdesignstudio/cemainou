<x-filament-panels::page>
    @if (! $usage)
        <div wire:init="measure" class="wsa-storage-measuring">
            <p class="wsa-meta">{{ __('Measuring the folders on the server...') }}</p>
        </div>
    @else
    {{-- Spațiul pe server: banda cu totalurile, apoi fiecare parte (cât ocupă, ce e, ce se poate elibera), apoi cele mai mari fișiere --}}
    @php
        $size = fn (?int $bytes) => $bytes === null ? '-' : ($bytes ? \App\Support\Files::size($bytes) : '0');
        $areas = collect($usage['areas']);
        $max = max(1, $areas->max('size'));
        $by = $areas->keyBy('key');
        $freeable = ($by['updates']['size'] ?? 0) + ($by['logs']['size'] ?? 0);
        $info = [
            'files' => [__('Team files'), trans_choice(':count file on projects and tasks|:count files on projects and tasks', $by['files']['count'] ?? 0).'. '.__('They are deleted from their page, or from the list below.')],
            'backups' => [__('Backups'), trans_choice(':count archive|:count archives', $by['backups']['count'] ?? 0).'. '.__('The newest :keep are kept (Settings → Backup).', ['keep' => $keep])],
            'database' => [__('Database'), $by['database']['size'] === null ? __('On the database server, not here.') : __('Everything the team wrote: updates, tasks, hours, comments.')],
            'updates' => [__('Old code copies'), __('The code of the version before the last update, kept for a manual return; it goes by itself after 7 days. Safe to delete.')],
            'logs' => [__('Logs'), __('The application\'s errors, by day; older than 14 days they go by themselves.')],
            'cache' => [__('Cache and sessions'), __('Rebuilt by themselves; nothing to do.')],
            'feedback' => [__('Feedback and logo'), __('Screenshots sent with feedback, and the company logo.')],
            'code' => [__('Application code'), __('Libraries and pages; replaced at every update.')],
        ];
        $actions = ['updates' => $this->deleteOldCodeAction, 'logs' => $this->deleteOldLogsAction, 'backups' => $this->pruneBackupsAction];
    @endphp

    <x-wsa.stats>
        <x-wsa.stat :label="__('cemainou in all')" main>{{ $size($usage['app']) }}</x-wsa.stat>
        <x-wsa.stat :label="__('The team\'s data')" :meta="__('data/: files, database, backups')">{{ $size($usage['data']) }}</x-wsa.stat>
        <x-wsa.stat :label="__('Can be freed')" :meta="__('old code and old logs')">{{ $size($freeable) }}</x-wsa.stat>
        @if ($usage['disk_free'] !== null)
            <x-wsa.stat :label="__('Free on the disk')" :meta="$usage['disk_total'] ? __('of :total; the hosting account may have a lower limit', ['total' => $size($usage['disk_total'])]) : null">{{ $size($usage['disk_free']) }}</x-wsa.stat>
        @endif
    </x-wsa.stats>

    <section class="wsa-block">
        <header class="wsa-block-head">
            <h2>{{ __('What takes the space') }}</h2>
            <span class="wsa-meta">{{ __('measured :time', ['time' => \Illuminate\Support\Carbon::parse($usage['measured_at'])->diffForHumans()]) }} · <button type="button" class="wsa-link-strong" wire:click="refresh" wire:loading.attr="disabled">{{ __('Measure again') }}</button></span>
        </header>
        <div class="wsa-disk">
            @foreach ($areas->sortByDesc('size') as $area)
                @php [$name, $about] = $info[$area['key']]; @endphp
                <div class="wsa-disk-row" wire:key="disk-{{ $area['key'] }}">
                    <div class="wsa-disk-name"><b>{{ $name }}</b><span class="wsa-meta">{{ $about }}</span></div>
                    <span class="wsa-h-share"><span style="width: {{ round(($area['size'] ?? 0) * 100 / $max, 1) }}%"></span></span>
                    <span class="wsa-disk-size">{{ $size($area['size']) }}</span>
                    <span class="wsa-disk-action">@if (isset($actions[$area['key']]) && ($area['size'] ?? 0) > 0){{ $actions[$area['key']] }}@endif</span>
                </div>
            @endforeach
        </div>
    </section>

    <section class="wsa-block">
        <header class="wsa-block-head"><h2>{{ __('The biggest files') }}</h2><span class="wsa-meta">{{ __('on projects and tasks') }}</span></header>
        @if ($biggest->isEmpty())
            <p class="wsa-empty">{{ __('No files yet.') }}</p>
        @else
            <div class="wsa-disk">
                @foreach ($biggest as $file)
                    <div class="wsa-disk-row" wire:key="file-{{ $file->id }}">
                        <div class="wsa-disk-name">
                            <a href="{{ $file->task ? $taskUrl($file->task) : ($file->project ? $projectUrl($file->project) : $file->href()) }}" class="wsa-link"><b>{{ $file->title }}</b></a>
                            <span class="wsa-meta">{{ collect([$file->project?->name, $file->task ? '#'.$file->task_id.' '.$file->task->title : null, $file->author?->name, $file->created_at->locale(app()->getLocale())->isoFormat('D MMM YYYY')])->filter()->join(' · ') }}</span>
                        </div>
                        <span class="wsa-h-share"><span style="width: {{ round(($file->size ?? 0) * 100 / max(1, $biggest->max('size')), 1) }}%"></span></span>
                        <span class="wsa-disk-size">{{ $size($file->size) }}</span>
                        <span class="wsa-disk-action">{{ ($this->deleteFileAction)(['id' => $file->id]) }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
    @endif
</x-filament-panels::page>
