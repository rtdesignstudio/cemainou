<?php

namespace App\Mail;

use App\Models\ProjectReport;
use App\Models\User;
use App\Support\Branding;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Raportul lunar, trimis oamenilor clientului: un rând de la cine îl trimite și linkul spre pagină. */
class MonthlyReport extends Mailable
{
    public function __construct(public ProjectReport $report, public User $sender, public User $person) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':month on :project: the monthly report', ['month' => $this->month(), 'project' => $this->report->project->name]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.monthly-report', text: 'mail.monthly-report-text', with: [
            'name' => Str::before($this->person->name, ' '), 'senderName' => $this->sender->name, 'studio' => Branding::name(),
            'project' => $this->report->project->name, 'month' => $this->month(), 'intro' => $this->report->intro, 'url' => $this->report->url(),
        ]);
    }

    private function month(): string
    {
        return Str::ucfirst(Carbon::parse($this->report->month.'-01')->locale(app()->getLocale())->isoFormat('MMMM YYYY'));
    }
}
