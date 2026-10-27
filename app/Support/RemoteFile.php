<?php

namespace App\Support;

use Closure;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Un fișier descărcat de server de la un link dat de agent (add_attachment cu source_url), în siguranță:
 * - doar https, pe portul standard; linkurile „share" de Google Drive devin linkuri de descărcare;
 * - fiecare adresă (și fiecare redirect, cel mult 3) se rezolvă aici, iar dacă duce la o adresă privată, rezervată sau la localhost se refuză;
 *   conexiunea merge apoi chiar la IP-ul verificat (fără o a doua rezolvare DNS, care ar putea întoarce altceva);
 * - se citește cel mult limita de mărime, iar tipul se verifică după conținut (imagine, PDF, text), nu după ce spune serverul.
 */
class RemoteFile
{
    public const MAX_REDIRECTS = 3;

    /** Rezolvarea numelor; în teste se înlocuiește. @var (Closure(string): list<string>)|null */
    public static ?Closure $resolver = null;

    /** @return array{bytes: string, filename: string, type: string} */
    public static function fetch(string $url, int $maxBytes, ?string $filename = null): array
    {
        $url = self::directDownload(trim($url));

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $ip] = self::check($url);
            $tooBig = false;
            try {
                $response = Http::withOptions([
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$ip}"]],               // la IP-ul verificat, nu la o nouă rezolvare
                    'allow_redirects' => false,
                    // se oprește cum trece de limită, nu după ce a descărcat tot
                    'progress' => function ($total, $downloaded) use ($maxBytes, &$tooBig) {
                        if ($downloaded > $maxBytes || $total > $maxBytes) {
                            $tooBig = true;
                            throw new RuntimeException('too large');
                        }
                    },
                ])->withHeaders(['User-Agent' => 'cemainou'])->withoutRedirecting()->connectTimeout(5)->timeout(20)->get($url);
            } catch (ConnectionException|RequestException|RuntimeException|TransferException) {
                throw new RuntimeException($tooBig ? self::tooLarge($maxBytes) : 'The link did not answer in time.');
            }

            if ($response->redirect()) {
                $next = $response->header('Location');
                if (! $next) {
                    throw new RuntimeException('The link redirects nowhere.');
                }
                $url = self::absolute($next, $url);

                continue;
            }
            if ($response->failed()) {
                throw new RuntimeException("The link answered {$response->status()}: is it public?");
            }
            if ((int) $response->header('Content-Length') > $maxBytes) {
                throw new RuntimeException(self::tooLarge($maxBytes));
            }

            $bytes = $response->body();
            if (strlen($bytes) > $maxBytes) {
                throw new RuntimeException(self::tooLarge($maxBytes));
            }
            if ($bytes === '') {
                throw new RuntimeException('The link gave an empty file.');
            }

            $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
            if (! str_starts_with($type, 'image/') && ! str_starts_with($type, 'text/') && $type !== 'application/pdf') {
                throw new RuntimeException("This kind of file is not accepted from a link ({$type}): images, PDFs and text files are. A Google Drive file must be shared with anyone who has the link.");
            }

            $name = self::name($filename, $response->header('Content-Disposition'), $url, $type);
            if ($type === 'text/html' && ! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['html', 'htm'], true)) {
                throw new RuntimeException('The link gave a web page, not the file (a sign-in or a preview page?). For Google Drive, share the file with anyone who has the link.');
            }

            return ['bytes' => $bytes, 'filename' => $name, 'type' => $type];
        }

        throw new RuntimeException('The link redirects too many times.');
    }

    /**
     * Începutul unei pagini web publice (pentru titlul unui link), cu aceleași verificări: doar https, fără adrese private, redirecturi
     * verificate la fiecare pas. Citește cel mult $maxBytes; null dacă pagina nu răspunde sau nu e HTML.
     */
    public static function page(string $url, int $maxBytes = 262144): ?string
    {
        try {
            for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
                [$host, $ip] = self::check($url);
                $response = Http::withOptions([
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$ip}"]],
                    'allow_redirects' => false,
                    'progress' => function ($total, $downloaded) use ($maxBytes) {
                        if ($downloaded > $maxBytes) {
                            throw new RuntimeException('enough');            // titlul e la început: restul nu mai trebuie
                        }
                    },
                ])->withHeaders(['User-Agent' => 'cemainou', 'Accept' => 'text/html'])->withoutRedirecting()->connectTimeout(3)->timeout(5)->get($url);
                if ($response->redirect() && $response->header('Location')) {
                    $url = self::absolute($response->header('Location'), $url);

                    continue;
                }

                return $response->successful() && str_contains(strtolower($response->header('Content-Type')), 'html') ? substr($response->body(), 0, $maxBytes) : null;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** Drive: /file/d/<id>/view sau open?id=<id> -> descărcarea directă. */
    public static function directDownload(string $url): string
    {
        if (preg_match('~^https://drive\.google\.com/(?:file/d/([\w-]+)|open\?(?:.*&)?id=([\w-]+))~', $url, $m)) {
            return 'https://drive.google.com/uc?export=download&id='.($m[1] ?: $m[2]);
        }

        return $url;
    }

    /** @return array{0: string, 1: string} gazda și IP-ul ei public; altfel refuz */
    private static function check(string $url): array
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new RuntimeException('Only https links are accepted.');
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new RuntimeException('Only links on the standard https port are accepted.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Links with a user or password inside are not accepted.');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new RuntimeException('This link points to a private or local address; only public addresses are allowed.');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (self::$resolver ?? self::resolve(...))($host);
        if ($ips === []) {
            throw new RuntimeException("The address {$host} could not be found.");
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
                throw new RuntimeException('This link points to a private or local address; only public addresses are allowed.');
            }
        }

        return [$host, $ips[0]];
    }

    /** @return list<string> adresele IPv4 și IPv6 ale numelui */
    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }

    private static function absolute(string $location, string $base): string
    {
        if (preg_match('~^https?://~i', $location)) {
            return $location;
        }
        $b = parse_url($base);

        return 'https://'.$b['host'].(str_starts_with($location, '/') ? $location : '/'.ltrim(dirname($b['path'] ?? '/'), '/').'/'.$location);
    }

    /** Numele: cel dat, altfel din Content-Disposition, altfel din adresă; cu extensia potrivită tipului, dacă lipsește. */
    private static function name(?string $given, ?string $disposition, string $url, string $type): string
    {
        $name = trim((string) $given);
        if ($name === '' && $disposition) {
            if (preg_match("~filename\\*=UTF-8''([^;]+)~i", $disposition, $m)) {
                $name = rawurldecode(trim($m[1], '"'));
            } elseif (preg_match('~filename="?([^";]+)"?~i', $disposition, $m)) {
                $name = trim($m[1]);
            }
        }
        if ($name === '') {
            $name = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))) ?: 'file';
        }
        $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif', 'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf', 'text/csv' => 'csv', 'text/markdown' => 'md', 'text/plain' => 'txt'];
        if (pathinfo($name, PATHINFO_EXTENSION) === '' && isset($extensions[$type])) {
            $name .= '.'.$extensions[$type];
        }

        return $name;
    }

    private static function tooLarge(int $maxBytes): string
    {
        return 'The file is larger than '.($maxBytes >= 1048576 ? intdiv($maxBytes, 1048576).' MB' : intdiv($maxBytes, 1024).' KB').'.';
    }
}
