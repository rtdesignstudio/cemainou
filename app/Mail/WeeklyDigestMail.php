<?php

namespace App\Mail;

use App\Models\Badge;
use App\Models\User;
use App\Support\Badges\Badges;
use App\Support\Badges\Catalog;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Rezumatul săptămânal al unui om (App\Support\WeeklyDigest), în limba și fusul lui. */
class WeeklyDigestMail extends Mailable
{
    public function __construct(public User $person, public array $projects, public CarbonInterface $start, public CarbonInterface $end) {}   // nu from / to: le are Mailable (expeditorul, destinatarii)

    private function period(): string
    {
        $format = fn (CarbonInterface $date) => $date->copy()->setTimezone($this->person->tz())->locale(app()->getLocale())->isoFormat('D MMMM');

        return $format($this->start).' - '.$format($this->end);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('cemainou: the week :period', ['period' => $this->period()]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.digest', text: 'mail.digest-text', with: [
            'period' => $this->period(),
            'rows' => $this->projects,
            'forClient' => $this->person->isClient(),
            'profileUrl' => filament()->getPanel('admin')->getProfileUrl(),
            'badges' => $this->badges(),
            'teamBadges' => $this->teamBadges(),
        ]);
    }

    /** Ce a câștigat echipa în săptămâna asta: câte badge-uri și cel mai des primit. Fără nume, fără clasament; clienții nu-l văd. */
    private function teamBadges(): ?string
    {
        if (! Badges::enabled() || $this->person->isClient()) {
            return null;
        }
        $keys = Badge::where('backfilled', false)->whereBetween('earned_at', [$this->start, $this->end])->pluck('key')->filter(fn ($k) => Catalog::get($k));
        if ($keys->count() < 2) {
            return null;
        }
        $top = $keys->countBy()->sortDesc();

        return __('The team earned :count badges this week; the most frequent: :name (:times).', ['count' => $keys->count(), 'name' => Catalog::name($top->keys()->first()), 'times' => $top->first()]);
    }

    /** Badge-urile câștigate de om în săptămâna asta (nu cele din istoric): „Săptămâna ta", deasupra proiectelor. Fără comparații cu alții. */
    private function badges(): array
    {
        if (! Badges::for($this->person)) {
            return [];
        }

        return $this->person->badges()->where('backfilled', false)->whereBetween('earned_at', [$this->start, $this->end])->orderBy('earned_at')->get()
            ->filter(fn ($b) => $b->definition())
            ->map(fn ($b) => Catalog::name($b->key).($b->tier > 1 ? ' '.Badges::roman($b->tier) : '').' · '.Catalog::levelLabel($b->key, $b->tier))->values()->all();
    }
}
