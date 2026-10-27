{{-- Setări → „Email to task": cum ajung emailurile la cemainou, cu oricare din cele două căi (App\Support\InboundMail) --}}
<div class="wsa-inbound-setup">
    <div>
        <b>{{ __('With your email server (a forwarder to a program)') }}</b>
        <p class="wsa-meta">{{ __('In the hosting panel (DirectAdmin, cPanel), add a forwarder for the mailbox that pipes to this command:') }}</p>
        <code>|{{ $command }}</code>
    </div>
    <div>
        <b>{{ __('Or with an email service (webhook)') }}</b>
        <p class="wsa-meta">{{ __('Cloudflare Email Routing with a Worker, Mailgun or Postmark can send each email, raw, to this address (POST). Keep it private: whoever has it can add tasks as the senders.') }}</p>
        <code>{{ $webhook }}</code>
    </div>
</div>
{{-- ultimele emailuri primite: că legătura merge și ce s-a întâmplat cu fiecare --}}
<div class="wsa-inbound-recent">
    <b>{{ __('Last emails received') }}</b>
    @forelse ($recent as $mail)
        <p class="wsa-meta">{{ \Illuminate\Support\Carbon::parse($mail['at'])->setTimezone(auth()->user()->tz())->locale(app()->getLocale())->isoFormat('D MMM, HH:mm') }} · {{ $mail['from'] ?? __('unknown sender') }} · {{ $mail['result'] }}</p>
    @empty
        <p class="wsa-meta">{{ __('None yet.') }}</p>
    @endforelse
</div>
