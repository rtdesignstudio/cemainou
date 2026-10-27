<?php

namespace App\Support;

use App\Filament\Pages\ErrorLog as ErrorLogPage;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Erorile din jurnalele aplicației (storage/logs/laravel-AAAA-LL-ZZ.log, unul pe zi), grupate: același mesaj, de câte ori, unde (fișierul și rândul)
 * și pe ce pagină (contextul pus la fiecare eroare, în bootstrap/app.php). Dimineața, adminii primesc în clopoțel erorile de ieri (morning());
 * pagina Erori (Admin) le arată pe ultimele 7 zile. Ca o problemă să se afle din aplicație, nu din inboxul cuiva.
 */
class ErrorLog
{
    public const DAYS = 7;

    private const MAX_BYTES = 5_000_000;

    /** Pagina pe care a apărut eroarea, pentru context: numele rutei sau calea, cu tokenurile lungi ascunse (linkurile de raport, de încărcare). */
    public static function page(): string
    {
        if (app()->runningInConsole()) {
            return 'console';
        }
        $request = request();
        $name = $request->route()?->getName();

        return $request->method().' '.($name ?: preg_replace('#/[A-Za-z0-9]{20,}#', '/…', '/'.ltrim($request->path(), '/')));
    }

    /**
     * Erorile unei zile, grupate după mesaj (cifrele contează ca una), cele mai dese întâi.
     *
     * @return list<array{message: string, count: int, last: Carbon, where: ?string, pages: list<string>}>
     */
    public static function day(string $date): array
    {
        $file = storage_path("logs/laravel-{$date}.log");
        if (! is_file($file)) {
            return [];
        }
        $handle = fopen($file, 'r');
        $groups = [];
        $read = 0;
        while (($line = fgets($handle)) !== false && $read < self::MAX_BYTES) {
            $read += strlen($line);
            if (! preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/', $line, $m)) {
                continue;
            }
            $message = trim(Str::before($m[3], ' {"'));
            preg_match('/"page":"((?:[^"\\\\]|\\\\.)*)"/', $m[3], $page);
            preg_match('/ at (\/[^:]+):(\d+)\)/', $m[3], $at);
            $key = md5(preg_replace('/\d+/', '#', $message));
            $groups[$key] ??= ['message' => Str::limit($message, 300), 'count' => 0, 'last' => null, 'where' => null, 'pages' => []];
            $groups[$key]['count']++;
            $groups[$key]['last'] = Carbon::parse($m[1], config('app.timezone'));
            $groups[$key]['where'] ??= $at ? Str::after($at[1], base_path().'/').':'.$at[2] : null;
            if ($page && count($groups[$key]['pages']) < 3 && ! in_array($p = stripslashes($page[1]), $groups[$key]['pages'], true)) {
                $groups[$key]['pages'][] = $p;
            }
        }
        fclose($handle);

        return collect($groups)->sortByDesc('count')->values()->all();
    }

    /** Ultimele zile, cele mai noi întâi. @return array<string, list<array<string, mixed>>> data => grupurile */
    public static function recent(int $days = self::DAYS): array
    {
        return collect(range(0, $days - 1))->mapWithKeys(fn (int $i) => [($d = now()->subDays($i)->toDateString()) => self::day($d)])->filter()->all();
    }

    /** Dimineața (din curățenia zilnică): adminii află de erorile de ieri, dacă au fost. */
    public static function morning(): int
    {
        $groups = self::day(now()->subDay()->toDateString());
        $total = array_sum(array_column($groups, 'count'));
        if (! $total) {
            return 0;
        }
        foreach (User::where('role', 'admin')->where('is_active', true)->get() as $admin) {
            $locale = $admin->locale ?: config('app.locale');
            $body = collect($groups)->take(3)->map(fn (array $g) => '×'.$g['count'].' '.Str::limit($g['message'], 90).($g['pages'] ? ' ('.$g['pages'][0].')' : ''))->join("\n");
            Inbox::notify($admin, trans_choice(':count error on the server yesterday|:count errors on the server yesterday', $total, ['count' => $total], $locale),
                $body, ErrorLogPage::getUrl(panel: 'admin'), Heroicon::OutlinedExclamationTriangle, $locale, 'errors');
        }

        return $total;
    }
}
