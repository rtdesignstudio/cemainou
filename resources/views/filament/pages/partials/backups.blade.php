{{-- lista backup-urilor din Setări: cele mai noi întâi, fiecare de descărcat (doar adminul) --}}
<div class="wsa-backups">
    @forelse ($backups as $backup)
        <a href="{{ route('backups.download', $backup['name']) }}" class="wsa-link-row">
            <span>{{ $backup['at']->setTimezone(auth()->user()->tz())->locale(app()->getLocale())->isoFormat('D MMMM YYYY, HH:mm') }}</span>
            <span class="wsa-meta">{{ \App\Support\Files::size($backup['size']) }}</span>
        </a>
    @empty
        <p class="wsa-empty">{{ __('No backup yet.') }}</p>
    @endforelse
</div>
