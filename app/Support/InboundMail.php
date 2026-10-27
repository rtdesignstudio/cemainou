<?php

namespace App\Support;

use App\Enums\Source;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Message;

/**
 * Emailul care devine sarcină. Fiecare proiect are adresa lui: cutia din setări (inbound_address) cu cheia proiectului după „+"
 * (tasks+k3x9…@domeniu). Un email trimis sau redirecționat acolo devine sarcină pe proiect: subiectul e titlul, textul sunt detaliile,
 * fișierele se atașează. Doar de la oameni cu cont activ care văd proiectul (și clienții, pe proiectele lor: cererea merge la lider);
 * restul, răspunsurile automate și dublurile (același Message-ID) se ignoră în tăcere. Emailul ajunge aici dintr-un forwarder „pipe"
 * al serverului de email (cemainou:inbound-mail) sau dintr-un webhook (POST /api/inbound/mail/{token}, emailul brut în corp).
 */
class InboundMail
{
    public const MAX_BYTES = 30 * 1024 * 1024;

    public const MAX_FILES = 6;

    public const RECENT = 'cemainou.inbound.recent';

    /** Ultimele emailuri primite, cu ce s-a întâmplat cu ele (Setări → „Emailul devine sarcină"): ca adminul să vadă că legătura merge. @return list<array{at: string, from: ?string, result: string, task: ?int}> */
    public static function recent(): array
    {
        return Cache::get(self::RECENT, []);
    }

    private static function remember(?string $from, string $result, ?int $task = null): void
    {
        Cache::forever(self::RECENT, array_slice([['at' => now()->toIso8601String(), 'from' => $from, 'result' => $result, 'task' => $task], ...self::recent()], 0, 10));
    }

    /** Adresa proiectului (cheia se face la prima cerere); null cât timp cutia nu e setată. */
    public static function addressFor(Project $project): ?string
    {
        $inbox = Settings::get('inbound_address');
        if (! filled($inbox) || ! str_contains($inbox, '@')) {
            return null;
        }
        if (! $project->inbox_key) {
            $project->forceFill(['inbox_key' => Str::lower(Str::random(12))])->saveQuietly();
        }
        [$local, $domain] = explode('@', $inbox, 2);

        return Str::before($local, '+').'+'.$project->inbox_key.'@'.$domain;
    }

    /** Cheia webhook-ului: din APP_KEY, ca să nu mai fie un secret de păstrat. */
    public static function token(): string
    {
        return substr(hash_hmac('sha256', 'inbound-mail', (string) config('app.key')), 0, 32);
    }

    /** Emailul brut (RFC 822) → sarcina făcută, sau null cu motivul în jurnal. */
    public static function receive(string $raw): ?Task
    {
        if ($raw === '' || strlen($raw) > self::MAX_BYTES || ! filled(Settings::get('inbound_address'))) {
            return self::skip('empty, too large or turned off');
        }
        $from = null;
        try {
            $message = Message::from($raw, false);
        } catch (Throwable $e) {
            return self::skip('unreadable: '.$e->getMessage());
        }

        $from = strtolower(trim((string) ($message->getHeader('From') instanceof AddressHeader ? $message->getHeader('From')->getEmail() : ''))) ?: null;

        // răspunsurile automate (concediu, livrare eșuată, liste) nu sunt cereri și ar putea face bucle
        $auto = strtolower((string) $message->getHeaderValue('Auto-Submitted'));
        if (($auto !== '' && $auto !== 'no') || in_array(strtolower((string) $message->getHeaderValue('Precedence')), ['bulk', 'list', 'junk', 'auto_reply'], true)
            || $message->getHeader('X-Autoreply') || $message->getHeader('X-Autorespond')) {
            return self::skip('automatic reply', $from);
        }

        // expeditorul se poate falsifica: când serverul de email a verificat și a picat (DMARC sau și SPF, și DKIM), emailul nu intră
        // doar antetul de sus, scris de serverul care a primit emailul (cele de dedesubt le poate adăuga oricine, chiar un „dkim=pass" fals)
        $auth = strtolower((string) ($message->getAllHeadersByName('Authentication-Results')[0] ?? null)?->getRawValue());
        if (str_contains($auth, 'dmarc=fail') || (str_contains($auth, 'spf=fail') && ! str_contains($auth, 'dkim=pass'))) {
            return self::skip('failed sender authentication', $from);
        }

        $project = self::project($message);
        if (! $project) {
            return self::skip('no project address among the recipients', $from);
        }
        $user = $from ? User::whereRaw('lower(email) = ?', [$from])->where('is_active', true)->first() : null;
        if (! $user || ! $user->canSeeProject($project) || $project->is_archived) {
            return self::skip("no account that sees {$project->name}", $from);
        }

        $id = trim((string) $message->getHeaderValue('Message-ID'), ' <>');
        if ($id !== '' && ! Cache::add('cemainou.inbound.'.sha1($id), 1, now()->addDays(30))) {
            return self::skip('already received', $from);
        }

        $text = self::text($message);
        $subject = trim(preg_replace('/^\s*((re|fwd?|tr|aw|wg|rv|răsp)\s*(\[\d+\])?\s*:\s*)+/iu', '', (string) $message->getHeaderValue('Subject')));
        $title = Str::limit($subject !== '' ? $subject : (Str::of($text)->trim()->explode("\n")->first() ?: __('Email from :name', ['name' => $user->name], $user->locale)), 200, '');
        $notes = Str::limit($text, Task::MAX_NOTES - 100, '…') ?: null;
        $assignee = $user->isClient() ? ($project->lead?->is_active ? $project->lead : null) : null;   // cererea clientului: la liderul proiectului

        $task = Task::add($project, $user, $title, $notes, $assignee, source: Source::Email);
        foreach (array_slice($message->getAllAttachmentParts(), 0, self::MAX_FILES) as $part) {
            $name = (string) ($part->getFilename() ?: 'fisier');
            $content = $part->getBinaryContentStream()?->getContents() ?? '';
            if ($content === '' || strlen($content) > Files::maxKb() * 1024 || ! Files::allowed($name)) {
                continue;
            }
            Files::attach($project, $user, $content, $name, null, Source::Email, $task);
        }

        self::remember($from, "task #{$task->id} on {$project->name}", $task->id);

        return $task;
    }

    /** Proiectul din adresele destinatarilor: partea de după „+" (To, Cc, apoi antetele puse de serverul de email). */
    private static function project(Message $message): ?Project
    {
        $addresses = [];
        foreach (['To', 'Cc', 'Delivered-To', 'X-Original-To', 'Envelope-To'] as $name) {
            $header = $message->getHeader($name);
            if ($header instanceof AddressHeader) {
                foreach ($header->getAddresses() as $address) {
                    $addresses[] = (string) $address->getEmail();
                }
            } elseif ($header) {
                $addresses[] = (string) $header->getValue();
            }
        }
        foreach ($addresses as $address) {
            if (preg_match('/\+([a-z0-9]{12})@/i', $address, $m) && ($project = Project::where('inbox_key', strtolower($m[1]))->first())) {
                return $project;
            }
        }

        return null;
    }

    /** Textul emailului: partea text, altfel HTML-ul fără etichete; fără semnătura de după „-- ". */
    private static function text(Message $message): string
    {
        $text = $message->getTextContent();
        if (! filled($text) && filled($html = $message->getHtmlContent())) {
            $text = html_entity_decode(strip_tags(preg_replace(['/<(br|\/p|\/div|\/li|\/tr|\/h\d)[^>]*>/i', '/<(script|style)[^>]*>.*?<\/\1>/is'], ["\n", ''], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $text = str_replace("\r\n", "\n", (string) $text);
        $text = preg_split('/\n-- \n/', $text)[0];

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    private static function skip(string $why, ?string $from = null): null
    {
        Log::info('cemainou inbound mail skipped: '.$why);
        self::remember($from, 'ignored: '.$why);

        return null;
    }
}
