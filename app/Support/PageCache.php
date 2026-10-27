<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Cache de un minut pentru părțile grele ale paginilor (cardurile de proiecte de pe Azi, Săptămâna), pe om și pe parametri. Se păstrează
 * HTML-ul gata desenat (text): cache-ul aplicației nu reface obiecte (cache.serializable_classes = false, o protecție pe care o păstrăm).
 * Se reface imediat ce se schimbă ceva scris de oameni (un update, o sarcină, timpul adăugat, un proiect, un comentariu); timpul măsurat
 * din sesiuni poate întârzia cel mult un minut.
 */
class PageCache
{
    public const SECONDS = 60;

    /** HTML-ul unei părți de pagină, desenat o dată pe minut (sau imediat ce cineva scrie ceva). */
    public static function html(string $name, User $viewer, array $params, Closure $render): HtmlString
    {
        $key = 'cemainou.page.'.$name.'.'.$viewer->id.'.'.md5(json_encode([$params, $viewer->locale, $viewer->tz(), app()->getLocale(), config('cemainou.version'), self::stamp()]));
        try {
            $html = Cache::remember($key, self::SECONDS, fn () => (string) $render());
        } catch (Throwable) {
            $html = (string) $render();                          // cache-ul nu merge: se desenează direct
        }

        return new HtmlString(is_string($html) ? $html : (string) $render());
    }

    /**
     * Date simple (doar text, numere, liste; niciun obiect), păstrate $seconds și refăcute imediat ce cineva scrie ceva (stamp) sau începe o zi
     * nouă: graficele de pe pagina unui om sau a unui proiect. Timpul măsurat de azi poate întârzia cel mult $seconds.
     */
    public static function data(string $name, User $viewer, array $params, Closure $build, int $seconds = 600): mixed
    {
        $key = 'cemainou.data.'.$name.'.'.md5(json_encode([$params, $viewer->tz(), app()->getLocale(), now($viewer->tz())->toDateString(), config('cemainou.version'), self::stamp()]));
        try {
            $cached = Cache::get($key);
            if ($cached !== null) {
                return $cached;
            }
            $value = $build();
            Cache::put($key, $value, $seconds);

            return $value;
        } catch (Throwable) {
            return $build();
        }
    }

    /**
     * Amprenta a ce au scris oamenii: se schimbă la orice update, rând, sarcină (și mișcarea ei), timp adăugat, proiect, comentariu, document
     * sau om nou. Nu și la vizitele oamenilor (users.updated_at se mișcă la fiecare deschidere a paginii Azi).
     */
    public static function stamp(): string
    {
        $request = request();                                                   // o dată pe cerere: o pagină are mai multe bucăți păstrate
        if (is_string($memo = $request->attributes->get('cemainou.stamp'))) {
            return $memo;
        }
        $parts = [];
        foreach (['updates', 'tasks', 'time_entries', 'projects', 'update_comments', 'task_comments', 'documents', 'attachments'] as $table) {
            $row = DB::table($table)->selectRaw('max(updated_at) as at, max(id) as id')->first();   // și id-ul: un rând nou în aceeași secundă tot schimbă amprenta
            $parts[] = $row->at.'#'.$row->id;
        }
        foreach (['update_items', 'task_events', 'users'] as $table) {
            $parts[] = DB::table($table)->max('id');
        }
        $parts[] = DB::table('update_items')->max('resolved_at');              // un rând rezolvat (Blocat / Urmează)
        $stamp = implode('|', $parts);
        $request->attributes->set('cemainou.stamp', $stamp);

        return $stamp;
    }
}
