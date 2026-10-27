{{-- Pagina linkului de încărcare: unde ajung fișierele, alegerea lor (din galerie sau camera telefonului), „Trimite"; apoi „Gata". Stilul paginilor fără Filament. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('Add files') }} - cemainou</title>
    @include('partials.favicon')
    @include('partials.plain-style')
    <style>
        .picks { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; margin-top: 1.5rem; }
        .pick { position: relative; display: flex; align-items: center; justify-content: center; min-height: 8rem; border: 1px dashed #a3a3a3; color: #525252; text-align: center; padding: 1rem; cursor: pointer; line-height: 1.4; }
        .pick b { color: #0a0a0a; font-weight: 500; font-size: 1.0625rem; }
        .pick:hover { border-color: #0a0a0a; color: #0a0a0a; }
        .pick input { position: absolute; width: 1px; height: 1px; opacity: 0; }
        #chosen { margin: 1rem 0 0; padding: 0; list-style: none; }
        #chosen li { display: flex; justify-content: space-between; gap: 1rem; padding: .55rem 0; border-bottom: 1px solid #e5e5e5; font-size: .9375rem; }
        #chosen li span:last-child { color: #737373; white-space: nowrap; }
        .done { border-top: 1px solid #0a0a0a; padding-top: 1rem; margin-top: 2rem; font-size: 1.0625rem; }
        a.button { display: inline-flex; align-items: center; height: 2.9rem; padding: 0 1.5rem; margin-top: 1.5rem; background: #0a0a0a; color: #fff; text-decoration: none; font-weight: 500; }
    </style>
</head>
<body>
<main>
    <span class="brand">cemainou</span>

    @if ($done)
        <h1>{{ __('Added') }}</h1>
        <p class="lead">{{ trans_choice(':count file added.|:count files added.', $done) }} {{ __('You can close this page.') }}</p>
    @elseif (! $link)
        <h1>{{ __('This link no longer works') }}</h1>
        <p class="lead">{{ __('Upload links work once, for :minutes minutes. Ask your agent for a new one.', ['minutes' => \App\Models\UploadLink::MINUTES]) }}</p>
    @else
        <h1>{{ __('Add files') }}</h1>
        <p class="lead">
            @if ($link->task)
                {{ __('To task #:id «:title», on :project.', ['id' => $link->task->id, 'title' => $link->task->title, 'project' => $link->project->name]) }}
            @else
                {{ __('To the project :project.', ['project' => $link->project->name]) }}
            @endif
            {{ __('They appear in the name of :name.', ['name' => $link->user->name]) }}
        </p>

        @if ($errors->any())
            <div class="error">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
        @endif

        <form method="POST" action="{{ route('upload-link.store', $token) }}" enctype="multipart/form-data">
            @csrf
            {{-- pe Android, galeria (selectorul de poze) se deschide doar pentru un câmp numai cu imagini și video; restul fișierelor, din al doilea --}}
            <div class="picks">
                <label class="pick">
                    <input type="file" name="files[]" multiple accept="image/*,video/*">
                    <span><b>{{ __('Photos and videos') }}</b><br>{{ __('from the gallery or the camera') }}</span>
                </label>
                <label class="pick">
                    <input type="file" name="files[]" multiple accept="image/*,application/pdf,video/*,.html,.txt,.md,.csv,.docx,.xlsx,.pptx,.key,.pages,.fig,.sketch,.zip">
                    <span><b>{{ __('Files') }}</b><br>{{ __('PDF, documents, designs') }}</span>
                </label>
            </div>
            <p class="hint">{{ __('Up to :count files, :size MB each.', ['count' => \App\Models\UploadLink::MAX_FILES, 'size' => intdiv(\App\Support\Files::maxKb(), 1024)]) }}</p>
            <ul id="chosen"></ul>
            <button type="submit">{{ __('Send') }}</button>
            <p class="hint">{{ __('The link works once, until :time.', ['time' => $link->expires_at->copy()->setTimezone($link->user->tz())->format('H:i')]) }}</p>
        </form>
        <script>
            (() => {
                const inputs = [...document.querySelectorAll('.pick input')], list = document.getElementById('chosen'), form = inputs[0].form, button = form.querySelector('button');
                const size = (b) => b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
                const render = () => {
                    list.innerHTML = '';
                    inputs.flatMap((i) => [...i.files]).forEach((f) => { const li = document.createElement('li'); li.innerHTML = '<span></span><span></span>'; li.children[0].textContent = f.name; li.children[1].textContent = size(f.size); list.appendChild(li); });
                    button.disabled = !inputs.some((i) => i.files.length);
                };
                inputs.forEach((i) => i.addEventListener('change', render));
                render();
                form.addEventListener('submit', () => { button.disabled = true; button.textContent = @json(__('Sending…')); });
            })();
        </script>
    @endif
</main>
</body>
</html>
