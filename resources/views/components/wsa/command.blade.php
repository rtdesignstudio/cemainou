@props(['command', 'description' => null])
{{-- o comandă de copiat (cu „Copy") și, dedesubt, ce face --}}
<div class="wsa-command" x-data="{ copied: false }">
    <div class="wsa-code">
        <code x-ref="command">{{ $command }}</code>
        <button type="button" class="wsa-take" x-on:click="const el = $refs.command, done = () => { copied = true; setTimeout(() => copied = false, 2000) }; if (navigator.clipboard) { navigator.clipboard.writeText(el.innerText).then(done).catch(() => {}) } else { const r = document.createRange(); r.selectNodeContents(el); const s = getSelection(); s.removeAllRanges(); s.addRange(r); if (document.execCommand('copy')) done() }">
            <span x-show="! copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak>{{ __('Copied') }}</span>
        </button>
    </div>
    @if ($description)
        <p class="wsa-command-desc">{{ $description }}</p>
    @endif
</div>
