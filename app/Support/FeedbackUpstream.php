<?php

namespace App\Support;

use App\Models\Feedback;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Feedback-ul centralizat: o instalare care a ales (Setări → „Send feedback to RT") își trimite feedback-ul despre cemainou la instanța RT
 * (cemainou.feedback_upstream), imediat după răspuns; ce n-a ajuns se reîncearcă la curățenia zilnică, trei zile. Pleacă textul, pagina fără
 * domeniu, ecranul, browserul, versiunea, fișierele și un număr al instalării (aleator); niciodată numele sau emailul omului.
 * La RT (cemainou.feedback_inbox), feedback-ul primit apare la Feedback fără autor, cu numărul instalării.
 */
class FeedbackUpstream
{
    /** Instalarea trimite: a ales în Setări, are unde, și nu e chiar instanța care primește. */
    public static function enabled(): bool
    {
        return (bool) Settings::get('feedback_to_rt') && filled(config('cemainou.feedback_upstream')) && ! self::inbox();
    }

    /** Instanța aceasta primește feedback de la instalări (doar la RT). */
    public static function inbox(): bool
    {
        return (bool) config('cemainou.feedback_inbox');
    }

    /** Numărul instalării: aleator, făcut o dată; nu spune nimic despre echipă. */
    public static function installation(): string
    {
        $row = Setting::firstOrCreate(['key' => 'installation_id'], ['value' => (string) Str::uuid()]);

        return (string) $row->value;
    }

    /** După răspuns (omul nu așteaptă), dacă instalarea a ales. */
    public static function queue(Feedback $feedback): void
    {
        if (self::enabled() && $feedback->user_id !== null) {
            $feedback->forceFill(['installation' => self::installation()])->save();   // marcat „de trimis": doar ce s-a scris cât era aleasă trimiterea
            defer(fn () => self::send($feedback));
        }
    }

    /** Trimite un feedback; true dacă a ajuns. O eroare (rețea, RT oprit) nu strică nimic: se reîncearcă la curățenie. */
    public static function send(Feedback $feedback): bool
    {
        try {
            $request = Http::timeout(30)->withUserAgent('cemainou/'.config('cemainou.version'))->acceptJson()->asMultipart();
            foreach ($feedback->files ?? [] as $file) {
                if (Storage::disk(Feedback::DISK)->exists($file['path'])) {
                    $request = $request->attach('files[]', Storage::disk(Feedback::DISK)->get($file['path']), $file['name']);
                }
            }
            $response = $request->post(config('cemainou.feedback_upstream'), array_filter([
                'body' => $feedback->body,
                'path' => $feedback->url ? (parse_url($feedback->url, PHP_URL_PATH) ?: '/') : null,   // fără domeniu: adresa instalării rămâne a ei
                'viewport' => $feedback->viewport, 'screen' => $feedback->screen, 'user_agent' => $feedback->user_agent,
                'version' => $feedback->app_version, 'installation' => self::installation(),
            ], fn ($value) => $value !== null));
            if ($response->successful()) {
                $feedback->forceFill(['sent_to_rt_at' => now()])->save();

                return true;
            }
        } catch (Throwable) {
        }

        return false;
    }

    /** La curățenia zilnică: ce era de trimis și n-a ajuns, din ultimele trei zile. */
    public static function retry(): void
    {
        if (! self::enabled()) {
            return;
        }
        Feedback::query()->whereNull('sent_to_rt_at')->whereNotNull('user_id')->whereNotNull('installation')->where('created_at', '>=', now()->subDays(3))
            ->limit(50)->get()->each(fn (Feedback $f) => self::send($f));
    }
}
