{{-- Azi, la client: conectarea unui agent, cu pașii direct aici (Claude Code și aplicația Claude, amândouă cu contul); × îl închide. --}}
<x-filament-widgets::widget>
    <section class="wsa-start wsa-agent-card">
        <header class="wsa-start-head">
            <div>
                <h2>{{ __('Connect your agent') }}</h2>
                <p class="wsa-meta">{{ __('Optional: Claude tells you what the team did and adds requests for you, right from the chat. No key: you approve it once with your cemainou account.') }}</p>
            </div>
            <x-wsa.close wire:click="hide" :title="__('I do not need it')" />
        </header>
        <div class="wsa-agent-card-cols">
            <div>
                <div class="wsa-step">Claude Code</div>
                <ol class="wsa-steps">
                    <li>{{ __('In the terminal, run:') }}<x-wsa.command :command="$claudeCode" /></li>
                    <li>{{ __('Start Claude Code, type /mcp, choose cemainou → Authenticate: cemainou opens in the browser; sign in, if it asks, and press Allow.') }}</li>
                    <li>{{ __('Then simply ask: “What has been done on our website this week?”') }}</li>
                </ol>
            </div>
            <div>
                <div class="wsa-step">{{ __('The Claude app: phone, web and desktop') }}</div>
                <ol class="wsa-steps">
                    <li>{{ __('On claude.ai or in the Claude desktop app, open Settings → Connectors and press “Add custom connector”.') }}</li>
                    <li>{{ __('Name: cemainou. Remote MCP server URL:') }}<x-wsa.command :command="$mcpUrl" /></li>
                    <li>{{ __('Press Add, then Connect, and Allow in cemainou. On the phone it shows up by itself, with the same Claude account.') }}</li>
                </ol>
            </div>
        </div>
        <p class="wsa-meta wsa-agent-card-more">{{ __('Once connected, this card goes away by itself.') }} <a href="{{ $profileUrl }}" class="wsa-link">{{ __('ChatGPT and more details') }}<x-wsa.arrow /></a></p>
    </section>
</x-filament-widgets::widget>
