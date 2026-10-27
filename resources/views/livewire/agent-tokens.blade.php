{{--
    Agenții și aplicațiile unui om: cheile pe dispozitive (pe calculator) și aplicațiile legate cu contul (OAuth).
    Fereastra de conectare începe cu o întrebare (pe calculator, în chat, alt client MCP); pe calculator, o singură comandă, întâi ca mesaj
    pentru agent, cu trei bife care se fac verzi singure. Detaliile: App\Livewire\AgentTokens.
--}}
<section class="wsa-block" x-on:modal-closed.window="if ($event.detail.id === 'connect-agent') $wire.forget()"
    @if ($openOnLoad) x-init="$nextTick(() => $dispatch('open-modal', { id: 'connect-agent' }))" @endif>
    <header class="wsa-block-head">
        <h2>{{ __('Agents and apps') }}</h2>
        <x-filament::button x-on:click="$wire.pick(null).then(() => $dispatch('open-modal', { id: 'connect-agent' }))">{{ __('Connect an agent or app') }}</x-filament::button>
    </header>
    @if ($forClient)
        {{-- clientul: doar aplicațiile, cu contul; ce vede agentul lui e ce vede el aici --}}
        <p class="wsa-meta wsa-intro">{{ __('Connect the Claude app or ChatGPT with your account: ask what was done on your projects, add a request or read what was shared with you, in a chat. Your agent sees only what you see here.') }}</p>
    @else
    <p class="wsa-meta wsa-intro">{{ __('On your computer, time is measured too; in a chat you only ask and report. Each computer gets its own key: revoke it when you change or lose the computer; keys unused for :days days are revoked by themselves.', ['days' => $idleDays]) }}</p>

    {{-- „Verifică acum": ce ajunge de la agenți și, unde lipsește ceva, ce faci --}}
    <div class="wsa-conn-check">
        <button type="button" class="wsa-inline-btn" wire:click="runCheck" wire:loading.attr="disabled" wire:target="runCheck">{{ __('Check the connection now') }}</button>
        @if ($check)
            <ul class="wsa-check-list" role="status">
                @foreach ($check as [$state, $text])
                    <li class="is-{{ $state }}">{{ $text }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="wsa-group">{{ __('On your computers') }}</div>
    @forelse ($tokens as $token)
        <div class="wsa-token" wire:key="token-{{ $token->id }}">
            <div>
                <div class="wsa-token-name">{{ $token->name }}</div>
                <div class="wsa-meta">
                    {{ $token->last_used_at ? __('last used :time', ['time' => $token->last_used_at->diffForHumans()]) : __('never used') }}
                    · {{ __('since :date', ['date' => $token->created_at->copy()->setTimezone(auth()->user()->tz())->locale(app()->getLocale())->isoFormat('D MMMM')]) }}
                </div>
            </div>
            <button type="button" class="wsa-take" wire:click="revoke({{ $token->id }})" wire:loading.attr="disabled" wire:target="revoke({{ $token->id }})" wire:confirm="{{ __('Revoke this key? The agent on that device stops working.') }}">{{ __('Revoke') }}</button>
        </div>
    @empty
        <p class="wsa-empty">{{ __('No agents connected yet.') }}
            <button type="button" class="wsa-inline-btn" x-on:click="$wire.pick('computer').then(() => $dispatch('open-modal', { id: 'connect-agent' }))">{{ __('Connect this computer') }}</button></p>
    @endforelse
    @if ($tokens->count() > 1)
        <p class="wsa-revoke-all">
            <button type="button" class="wsa-inline-btn" wire:click="revokeAll" wire:loading.attr="disabled" wire:target="revokeAll" wire:confirm="{{ __('Revoke all your keys? Every connected agent stops working until you connect it again.') }}">{{ __('Revoke all') }}</button>
        </p>
    @endif
    @endif

    <div class="wsa-group">{{ __('Apps connected with your account') }}</div>
    @forelse ($apps as $app)
        <div class="wsa-token" wire:key="app-{{ $app->client_id }}">
            <div>
                <div class="wsa-token-name">{{ $app->client?->name ?? __('An app') }}</div>
                <div class="wsa-meta">{{ __('approved :time', ['time' => $app->created_at->diffForHumans()]) }} · OAuth</div>
            </div>
            <button type="button" class="wsa-take" wire:click="disconnect('{{ $app->client_id }}')" wire:loading.attr="disabled" wire:confirm="{{ __('Disconnect this app? It stops working until you approve it again.') }}">{{ __('Disconnect') }}</button>
        </div>
    @empty
        <p class="wsa-empty">{{ __('No app connected yet.') }}
            <button type="button" class="wsa-inline-btn" x-on:click="$wire.pick('claude-app').then(() => $dispatch('open-modal', { id: 'connect-agent' }))">{{ __('The Claude app') }}</button>
            · <button type="button" class="wsa-inline-btn" x-on:click="$wire.pick('chatgpt').then(() => $dispatch('open-modal', { id: 'connect-agent' }))">ChatGPT</button></p>
    @endforelse

    @php
        $place = collect($places)->firstWhere('key', $target);
        $say = $forClient ? [                                                   // clientul: ce se lucrează la proiectele lui, o cerere nouă, un document partajat
            __('“What has been done on our website this week?”'),
            __('“Add a request on our website: the phone number in the footer is wrong”'),
            __('“Show me the documents shared with us”'),
            __('“Is there anything new for me?”'),
        ] : [
            __('“Report to cemainou what I did today on Auto Nord: two hours on the new gallery”'),
            __('“What are my tasks?”'),
            __('“Note an idea on cemainou: a dark mode”'),
            __('“Give me an upload link for #12”, for photos from the phone'),
        ];
    @endphp
    <x-filament::modal id="connect-agent" width="2xl" :close-by-clicking-away="false">
        @if ($target === null)
            {{-- 1. o singură întrebare --}}
            <x-slot name="heading">{{ __('Connect cemainou') }}</x-slot>
            <x-slot name="description">{{ $forClient ? __('In a chat, with your account: no key.') : __('Where do you work with an agent? On your computer, time is measured too; in a chat you only ask and report.') }}</x-slot>
            <div class="wsa-places">
                @foreach ($places as $p)
                    <button type="button" @class(['wsa-place', 'is-main' => $p['key'] === 'computer', 'is-minor' => $p['key'] === 'other']) wire:click="pick('{{ $p['key'] }}')">
                        <x-filament::icon :icon="$p['icon']" class="wsa-place-icon" />
                        <span class="wsa-place-text">@if ($p['key'] === 'computer')<em class="wsa-tag is-alert">{{ __('recommended') }}</em>@endif<b>{{ $p['name'] }}</b><span>{{ $p['line'] }}</span></span>
                        <x-wsa.arrow />
                    </button>
                @endforeach
            </div>
        @elseif ($target === 'computer')
            {{-- 2. pe calculator: comanda unică, întâi ca mesaj pentru agent; dedesubt, cele trei bife --}}
            <x-slot name="heading">{{ __('Connect this computer') }}</x-slot>
            <x-slot name="description">{{ __('One command for the whole computer: it finds Claude Code (also in the Claude desktop app and the editor), Cursor, Codex and Gemini CLI, and connects them all. It works once, for :minutes minutes; the key never shows up anywhere.', ['minutes' => \App\Support\ConnectCode::MINUTES]) }}</x-slot>
            @if ($command)
                <div class="wsa-step">{{ __('1. Paste this in your agent\'s chat (Claude Code, the Code tab of the Claude app, Cursor): it runs it') }}</div>
                <x-wsa.command :command="\App\Support\AgentCommands::connectMessage($command)" />
                <details class="wsa-connect-alt">
                    <summary>{{ __('Or run it yourself in a terminal') }}</summary>
                    <x-wsa.command :command="$command" />
                </details>
                @if ($progress)
                    <div class="wsa-step">{{ __('2. It turns green by itself') }}</div>
                    <ul class="wsa-connect-checks" @unless ($progress['tools'] && $progress['session']) wire:poll.3s @endunless role="status">
                        <li @class(['is-ok' => $progress['key']])>{{ $progress['key'] ? __('The key is created on this computer') : __('Waiting for the command…') }}</li>
                        <li @class(['is-ok' => $progress['session']])>{{ __('The first session arrived: time is measured') }}</li>
                        <li @class(['is-ok' => $progress['tools']])>{{ __('The agent used the cemainou tools') }}</li>
                    </ul>
                    @if ($progress['fix'])
                        <p class="wsa-connect-fix">{{ $progress['fix'] }}</p>
                    @elseif ($progress['key'] && $progress['session'] && $progress['tools'])
                        <p class="wsa-connect-fix is-done">{{ __('Done. Type /upd when you finish a piece of work; say the rest in plain words.') }}</p>
                    @endif
                @endif
            @endif
        @elseif ($target === 'chat')
            {{-- 2. în chat: care aplicație --}}
            <x-slot name="heading">{{ __('In a chat') }}</x-slot>
            <x-slot name="description">{{ __('With your account, no key. Which app do you use?') }}</x-slot>
            <div class="wsa-places">
                <button type="button" class="wsa-place" wire:click="pick('claude-app')">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedDevicePhoneMobile" class="wsa-place-icon" />
                    <span class="wsa-place-text"><b>{{ __('The Claude app') }}</b><span>{{ __('Phone, web and desktop chats.') }}</span></span>
                    <x-wsa.arrow />
                </button>
                <button type="button" class="wsa-place" wire:click="pick('chatgpt')">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedChatBubbleLeftRight" class="wsa-place-icon" />
                    <span class="wsa-place-text"><b>ChatGPT</b><span>{{ __('As a connector, in developer mode.') }}</span></span>
                    <x-wsa.arrow />
                </button>
            </div>
        @elseif ($target === 'other')
            {{-- alt client MCP: cheia, o singură dată --}}
            <x-slot name="heading">{{ __('Another MCP client') }}</x-slot>
            <x-slot name="description">{{ __('Copy the key now: it is shown only once. Revoke it here if it leaks.') }}</x-slot>
            @if ($plainToken)
                <div class="wsa-step">{{ __('The key') }}</div>
                <x-wsa.command :command="$plainToken" />
                <ol class="wsa-steps">
                    <li>{{ __('URL:') }} <x-wsa.command :command="$mcpUrl" /></li>
                    <li>{{ __('Header Authorization: Bearer <the key>, and X-Cemainou-Agent: the agent\'s name.') }}</li>
                </ol>
                <p class="wsa-meta wsa-connect-note">{{ __('Codex, Cursor or Gemini CLI on this computer? The command from “I work with an agent on my computer” connects them by itself.') }}</p>
            @endif
        @elseif ($target === 'claude-app')
            {{-- aplicația Claude: conectorul, aprobat cu contul --}}
            <x-slot name="heading">{{ __('The Claude app: phone, web and desktop') }}</x-slot>
            <x-slot name="description">{{ __('No key: you approve it once with your cemainou account, and it works on every device where you use Claude.') }}</x-slot>
            <ol class="wsa-steps">
                <li>{{ __('On claude.ai or in the Claude desktop app, open Settings → Connectors and press “Add custom connector”.') }}</li>
                <li>{{ __('Name: cemainou. Remote MCP server URL:') }}<x-wsa.command :command="$mcpUrl" /></li>
                <li>{{ __('Press Add, then Connect: cemainou opens; sign in, if it asks, and press Allow. Leave the advanced settings (OAuth Client ID) empty: cemainou registers Claude by itself.') }}</li>
                <li>{{ __('On the phone there is nothing to add: the connector shows up in the Claude app by itself, with the same Claude account.') }}</li>
                <li>{{ __('In a chat, check that cemainou is on in the tools menu (the + or the sliders button).') }}</li>
            </ol>
            <div class="wsa-step">{{ __('Then simply say') }}</div>
            <ul class="wsa-say">@foreach ($say as $line)<li>{{ $line }}</li>@endforeach</ul>
            <p class="wsa-meta wsa-connect-note">{{ $forClient ? __('Custom connectors may need a paid Claude plan. You disconnect the app from here.') : __('Time is not measured in chats: say how long it took, if it matters. Custom connectors may need a paid Claude plan. You disconnect the app from here.') }}</p>
        @else
            {{-- ChatGPT: conector în modul pentru dezvoltatori --}}
            <x-slot name="heading">ChatGPT</x-slot>
            <x-slot name="description">{{ __('No key: you approve it with your cemainou account. ChatGPT takes custom connectors in developer mode, on its paid plans; OpenAI renames these menus often, so look for “Developer mode” and “Create”.') }}</x-slot>
            <ol class="wsa-steps">
                <li>{{ __('On chatgpt.com: Settings → Apps & Connectors → Advanced settings, and turn on Developer mode.') }}</li>
                <li>{{ __('Back in Apps & Connectors, press Create. Name: cemainou; MCP server URL:') }}<x-wsa.command :command="$mcpUrl" /></li>
                <li>{{ __('Authentication: OAuth. Tick that you trust the app and press Create: cemainou opens; sign in and press Allow.') }}</li>
                <li>{{ __('In a chat, press + and choose cemainou (under Developer mode or More).') }}</li>
            </ol>
            <div class="wsa-step">{{ __('Then simply say') }}</div>
            <ul class="wsa-say">@foreach ($say as $line)<li>{{ $line }}</li>@endforeach</ul>
            <p class="wsa-meta wsa-connect-note">{{ $forClient ? __('You disconnect the app from here.') : __('Time is not measured in chats: say how long it took, if it matters. You disconnect the app from here.') }}</p>
        @endif

        <x-slot name="footerActions">
            @if ($target !== null)
                <x-filament::button color="gray" outlined wire:click="back" :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowLeft">{{ __('Back') }}</x-filament::button>
            @endif
            <x-filament::button x-on:click="$dispatch('close-modal', { id: 'connect-agent' })">{{ __('Close') }}</x-filament::button>
        </x-slot>
    </x-filament::modal>
</section>
