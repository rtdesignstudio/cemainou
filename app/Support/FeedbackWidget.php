<?php

namespace App\Support;

use App\Models\Project;
use App\Models\User;
use App\Models\WidgetDevice;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Widget-ul de feedback de pe site-ul clientului (15 oct 2026): pe site e o singură linie de cod, care pentru vizitatori nu face nimic.
 * Butonul apare doar pe dispozitivele activate de oameni cemainou cu acces la proiect: din cemainou (logat) se deschide site-ul cu un cod
 * de unică folosință după „#", pe care scriptul îl schimbă pe o cheie a dispozitivului, păstrată pe site (nu un cookie de la terți, pe care
 * browserele îl blochează). Ce se trimite intră pe proiect în numele omului. Doar de pe domeniile permise ale proiectului.
 */
class FeedbackWidget
{
    public const CODE_MINUTES = 10;

    /**
     * Adresele, curățate: domeniul fără schemă, port sau „www.", cu calea dacă e una (example.test/d/site: doar paginile de sub ea, ca pe același
     * site să stea și alt proiect).
     *
     * @return list<string>
     */
    public static function cleanDomains(mixed $domains): ?array
    {
        $clean = collect((array) $domains)->map(fn ($d) => self::address((string) $d))->filter()->unique()->values()->all();

        return $clean ?: null;
    }

    /** „https://www.example.test/d/site/?x" → „example.test/d/site"; „autonord.test" rămâne „autonord.test". */
    public static function address(string $value): string
    {
        $host = self::host($value);
        if ($host === '') {
            return '';
        }
        $value = trim($value);
        $path = trim((string) parse_url(str_contains($value, '://') ? $value : 'https://'.$value, PHP_URL_PATH), '/');

        return $path === '' ? $host : $host.'/'.$path;
    }

    public static function host(string $value): string
    {
        $value = trim(Str::lower($value));
        $host = parse_url(str_contains($value, '://') ? $value : 'https://'.$value, PHP_URL_HOST) ?: '';

        return preg_replace('/^www\./', '', $host);
    }

    /** Pentru script: unde apare butonul (domeniul și calea). @return list<array{h: string, p: string}> */
    public static function scope(Project $project): array
    {
        return collect($project->widget_domains ?? [])->map(fn (string $d) => ['h' => Str::before($d, '/'), 'p' => str_contains($d, '/') ? '/'.Str::after($d, '/') : ''])->values()->all();
    }

    /** E o adresă (Origin) de pe un domeniu permis al proiectului? Și subdomeniile lui (staging.autonord.test pentru autonord.test). */
    public static function allows(Project $project, ?string $origin): bool
    {
        $host = $origin ? self::host($origin) : '';
        if ($host === '' || ! $project->widget_enabled) {
            return false;
        }

        return collect($project->widget_domains ?? [])->contains(fn (string $d) => $host === Str::before($d, '/') || str_ends_with($host, '.'.Str::before($d, '/')));   // Origin-ul n-are cale: calea o verifică scriptul
    }

    /** Poate omul folosi widget-ul proiectului? (vede proiectul, contul e activ) */
    public static function canUse(User $user, Project $project): bool
    {
        return $user->is_active && $project->widget_enabled && ! $project->is_archived && $user->canSeeProject($project);
    }

    public const QR_MINUTES = 10;

    /** Un telefon de vizitator care n-a mai trimis nimic atâtea zile se oprește singur. */
    public const GUEST_IDLE_DAYS = 60;

    /**
     * Linkul pentru „QR fără logare": scanat, deschide site-ul cu butonul pornit pe telefonul acela, fără logare acolo; ce se trimite de pe el
     * e în numele omului care l-a cerut (feedback-ul e legat mereu de un om). Merge o dată, 10 minute.
     */
    public static function qrUrl(User $user, Project $project, string $domain): string
    {
        $token = Str::random(40);
        Cache::put('cemainou.widget.qr.'.$token, ['user' => $user->id, 'project' => $project->id, 'domain' => $domain], now()->addMinutes(self::QR_MINUTES));

        return route('widget.qr', ['token' => $token]);
    }

    /** Cuvintele unui text, pentru potriviri: mici, fără diacritice, de cel puțin 3 litere, fără cele de umplutură. @return list<string> */
    public static function words(string $text): array
    {
        $stop = ['the', 'and', 'for', 'with', 'this', 'that', 'not', 'are', 'care', 'este', 'sunt', 'din', 'pentru', 'sau', 'mai', 'cum', 'cand', 'unde', 'dar', 'ca', 'sa', 'nu', 'pe', 'la', 'in', 'cu', 'de', 'si', 'un', 'una', 'o', 'e', 'al', 'ale', 'lui', 'ei', 'asta', 'acest', 'aceasta', 'trebuie', 'tre', 'please', 'pls'];
        $words = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($text)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($words, fn (string $w) => mb_strlen($w) >= 3 && ! in_array($w, $stop, true))));
    }

    /** Codul QR, ca SVG, pentru un link. */
    public static function qrSvg(string $url): string
    {
        $renderer = new ImageRenderer(new RendererStyle(240, 1), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($url);
    }

    /** Un cod de activare de unică folosință, pentru omul logat, pe un domeniu al proiectului. */
    public static function code(User $user, Project $project, string $domain, bool $guest = false, ?int $invite = null): string
    {
        $code = Str::random(32);
        Cache::put('cemainou.widget.code.'.$code, ['user' => $user->id, 'project' => $project->id, 'domain' => $domain, 'guest' => $guest, 'invite' => $invite], now()->addMinutes(self::CODE_MINUTES));

        return $code;
    }

    /** Codul, schimbat pe cheia dispozitivului (o singură dată). @return array{token: string, device: WidgetDevice}|null */
    public static function redeem(Project $project, string $code, string $origin, ?string $agent): ?array
    {
        $data = Cache::pull('cemainou.widget.code.'.$code);
        $user = $data ? User::find($data['user']) : null;
        if (! $data || $data['project'] !== $project->id || ! $user || ! self::canUse($user, $project) || ! self::allows($project, $origin)) {
            return null;
        }
        $token = Str::random(48);
        $device = WidgetDevice::create(['user_id' => $user->id, 'project_id' => $project->id, 'token_hash' => hash('sha256', $token),
            'domain' => self::host($origin), 'device' => self::deviceName($agent), 'guest' => (bool) ($data['guest'] ?? false), 'invite_id' => $data['invite'] ?? null, 'last_used_at' => now()]);

        return ['token' => $token, 'device' => $device->setRelation('user', $user)];
    }

    /** Dispozitivul din cheia trimisă de script; null dacă nu mai e valabil (închis, om oprit, fără acces, widget oprit, alt domeniu). */
    public static function device(Project $project, ?string $token, ?string $origin): ?WidgetDevice
    {
        if (! $token || ! self::allows($project, $origin)) {
            return null;
        }
        $device = WidgetDevice::with('user')->where('project_id', $project->id)->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
        if (! $device || ! $device->user || ! self::canUse($device->user, $project)) {
            return null;
        }
        // vizitatorii: invitația lor încă ține (neoprită, neexpirată) și au mai trimis ceva în ultimele GUEST_IDLE_DAYS zile
        if ($device->guest && (($device->invite_id && ! $device->invite?->keepsDevices()) || ($device->last_used_at && $device->last_used_at->lt(now()->subDays(self::GUEST_IDLE_DAYS))))) {
            return null;
        }
        if (! $device->last_used_at || $device->last_used_at->lt(now()->subHour())) {
            $device->forceFill(['last_used_at' => now()])->save();
        }

        return $device;
    }

    /** „Safari pe iPhone", „Chrome pe Mac": ca omul să-și recunoască dispozitivele în profil. */
    public static function deviceName(?string $agent): string
    {
        $a = (string) $agent;
        $browser = match (true) {
            str_contains($a, 'Edg/') => 'Edge', str_contains($a, 'Firefox/') => 'Firefox', str_contains($a, 'Chrome/') => 'Chrome', str_contains($a, 'Safari/') => 'Safari', default => 'Browser',
        };
        $os = match (true) {
            str_contains($a, 'iPhone') => 'iPhone', str_contains($a, 'iPad') => 'iPad', str_contains($a, 'Android') => 'Android',
            str_contains($a, 'Mac OS') => 'Mac', str_contains($a, 'Windows') => 'Windows', str_contains($a, 'Linux') => 'Linux', default => null,
        };

        return $os ? "{$browser} · {$os}" : $browser;
    }

    /**
     * Linia de pus pe site: un mic script în pagină care, pentru vizitatori, nu face nicio cerere spre cemainou (deci nici adresa IP nu
     * ajunge la noi). Abia pe un dispozitiv activat (cheia salvată pe site) sau la activare (#wsa=) încarcă widget-ul de la noi.
     */
    public static function snippet(Project $project): ?string
    {
        if (! $project->widget_key) {
            return null;
        }
        $key = $project->widget_key;
        $src = route('widget.script', $key);

        return "<script>(function(k,u){try{if(localStorage.getItem('wsa-feedback-'+k)||/[#&]wsa=/.test(location.hash)){var s=document.createElement('script');s.src=u;s.async=true;document.head.appendChild(s)}}catch(e){}})('{$key}','{$src}');</script>";
    }

    /**
     * Contextul trimis de widget (pagina, ecranul, setările, erorile, elementul arătat), curățat și cu browserul citit pe server din
     * user-agent. Doar cheile cunoscute, texte scurtate: ajunge pe sarcină (site_context) și la agenți.
     *
     * @return array<string, mixed>
     */
    public static function context(array $raw, ?string $agent): array
    {
        $str = fn ($v, int $max) => is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $max) : null;
        $int = fn ($v) => is_numeric($v) ? (int) $v : null;
        $num = fn ($v) => is_numeric($v) ? round((float) $v, 2) : null;
        $pair = fn ($v) => is_array($v) && isset($v['w'], $v['h']) && is_numeric($v['w']) && is_numeric($v['h']) ? ['w' => (int) $v['w'], 'h' => (int) $v['h']] : null;
        $ua = self::agent($agent);
        $url = $str($raw['url'] ?? null, 2000);
        $url = $url && preg_match('#^https?://#i', $url) ? $url : null;     // pe sarcină devine link: doar http(s)

        $element = fn ($e) => is_array($e) && $str($e['selector'] ?? null, 500) ? array_filter([
            'selector' => $str($e['selector'], 500),
            'text' => $str($e['text'] ?? null, 200),
            'rect' => is_array($e['rect'] ?? null) ? array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, array_intersect_key($e['rect'], array_flip(['x', 'y', 'w', 'h']))) : null,
        ]) : null;
        $elements = collect(is_array($raw['elements'] ?? null) ? $raw['elements'] : [$raw['element'] ?? null])->take(5)->map($element)->filter()->unique('selector')->values()->all();

        return array_filter([
            'url' => $url,
            'path' => $url ? (parse_url($url, PHP_URL_PATH) ?: '/') : null,
            'title' => $str($raw['title'] ?? null, 300),
            'referrer' => $str($raw['referrer'] ?? null, 1000),
            'browser' => $ua['browser'], 'os' => $ua['os'], 'device' => $ua['device'],
            'viewport' => $pair($raw['viewport'] ?? null),
            'screen' => $pair($raw['screen'] ?? null),
            'dpr' => $num($raw['dpr'] ?? null),
            'orientation' => in_array($raw['orientation'] ?? null, ['portrait', 'landscape'], true) ? $raw['orientation'] : null,
            'scroll' => is_array($raw['scroll'] ?? null) ? array_filter(['y' => $int($raw['scroll']['y'] ?? null), 'max' => $int($raw['scroll']['max'] ?? null)], fn ($v) => $v !== null) ?: null : null,
            'lang' => $str($raw['lang'] ?? null, 20),
            'tz' => $str($raw['tz'] ?? null, 60),
            'theme' => in_array($raw['theme'] ?? null, ['light', 'dark'], true) ? $raw['theme'] : null,
            'time' => $str($raw['time'] ?? null, 40),
            'errors' => collect(is_array($raw['errors'] ?? null) ? $raw['errors'] : [])->take(10)
                ->map(fn ($e) => is_array($e) ? array_filter(['msg' => $str($e['msg'] ?? null, 300), 'src' => $str($e['src'] ?? null, 300), 'line' => $int($e['line'] ?? null)]) : null)
                ->filter(fn ($e) => $e && isset($e['msg']))->values()->all() ?: null,
            'requests' => collect(is_array($raw['requests'] ?? null) ? $raw['requests'] : [])->take(10)
                ->map(fn ($r) => is_array($r) && $str($r['url'] ?? null, 500) ? array_filter(['method' => strtoupper((string) $str($r['method'] ?? 'GET', 10)), 'url' => $str($r['url'], 500), 'status' => $int($r['status'] ?? null)], fn ($v) => $v !== null) : null)
                ->filter()->values()->all() ?: null,
            // elementele arătate pe pagină, cel mult 5 (se adună în widget); „element" rămâne primul, pentru semnele de pe pagină și datele vechi
            'element' => $elements[0] ?? null,
            'elements' => count($elements) > 1 ? $elements : null,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /** Browserul, sistemul și tipul dispozitivului, din user-agent: „Safari 18.1", „iOS 18.1", „telefon". @return array{browser: ?string, os: ?string, device: ?string} */
    public static function agent(?string $agent): array
    {
        $a = (string) $agent;
        $v = fn (string $re) => preg_match($re, $a, $m) ? str_replace('_', '.', $m[1]) : null;
        $browser = match (true) {
            str_contains($a, 'Edg/') => 'Edge '.$v('/Edg\/([\d.]+)/'),
            str_contains($a, 'OPR/') => 'Opera '.$v('/OPR\/([\d.]+)/'),
            str_contains($a, 'Firefox/') => 'Firefox '.$v('/Firefox\/([\d.]+)/'),
            str_contains($a, 'CriOS/') => 'Chrome '.$v('/CriOS\/([\d.]+)/'),
            str_contains($a, 'Chrome/') => 'Chrome '.$v('/Chrome\/([\d.]+)/'),
            str_contains($a, 'Safari/') => 'Safari '.$v('/Version\/([\d.]+)/'),
            default => null,
        };
        $os = match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/', $a) => 'iOS '.$v('/OS ([\d_]+)/'),
            str_contains($a, 'Android') => 'Android '.$v('/Android ([\d.]+)/'),
            str_contains($a, 'Mac OS X') => 'macOS '.$v('/Mac OS X ([\d_]+)/'),
            str_contains($a, 'Windows NT') => 'Windows '.match ($v('/Windows NT ([\d.]+)/')) {
                '10.0' => '10/11', '6.3' => '8.1', '6.1' => '7', default => ''
            },
            str_contains($a, 'Linux') => 'Linux',
            default => null,
        };
        $device = match (true) {
            (bool) preg_match('/iPad|Tablet/', $a) || (str_contains($a, 'Android') && ! str_contains($a, 'Mobile')) => 'tablet',
            (bool) preg_match('/iPhone|Mobile/', $a) => 'phone',
            $a !== '' => 'desktop',
            default => null,
        };

        return ['browser' => $browser ? trim(preg_replace('/(\d+\.\d+)[\d.]*/', '$1', $browser)) : null, 'os' => $os ? trim(preg_replace('/(\d+\.\d+)[\d.]*/', '$1', $os)) : null, 'device' => $device];
    }
}
