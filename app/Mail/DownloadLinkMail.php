<?php

namespace App\Mail;

use App\Models\DownloadRequest;
use App\Support\Settings;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/** Linkul de descărcare, trimis pe loc celui care și-l cere de pe pagina publică, cu pașii de instalare; în limba paginii lui. */
class DownloadLinkMail extends Mailable
{
    public function __construct(public DownloadRequest $download) {}

    public function envelope(): Envelope
    {
        $reply = Settings::get('privacy_email') ?: Settings::get('mail_from_address');   // un răspuns ajunge la oameni, nu la adresa de trimitere

        return new Envelope(subject: __('Your cemainou download link'), replyTo: $reply ? [new Address($reply)] : []);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.download-link', text: 'mail.download-link-text', with: [
            'greeting' => $this->download->name !== ''                                // numele e opțional pe pagină
                ? __('Hello :name, thank you for trying cemainou. The archive is below; it is free while up to 5 people use an installation.', ['name' => Str::before($this->download->name, ' ')])
                : __('Hello, thank you for trying cemainou. The archive is below; it is free while up to 5 people use an installation.'),
            'url' => DownloadRequest::latestUrl(), 'version' => $this->download->version,
            'page' => 'https://rt.md/cemainou/'.($this->download->lang === 'ro' ? 'ro/' : '').'#instalare',
        ]);
    }
}
