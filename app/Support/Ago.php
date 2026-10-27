<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\HtmlString;

/** Cât a trecut: scurt, pentru eticheta unui card („33 min", „5 h", „2 zile", apoi data), și exact, pentru hover („joi, 8 octombrie 2026, 14:32"). */
class Ago
{
    /** Ziua, pentru separatorii listelor: „Azi", „Ieri", „marți, 6 octombrie" (cu anul, dacă e altul), în fusul celui care se uită. */
    public static function day(CarbonInterface $at): string
    {
        $tz = auth()->user()?->tz() ?? config('app.timezone');
        $local = $at->copy()->setTimezone($tz)->locale(app()->getLocale());
        $today = now($tz)->startOfDay();

        return match (true) {
            $local->isSameDay($today) => __('Today'),
            $local->isSameDay($today->copy()->subDay()) => __('Yesterday'),
            default => ucfirst($local->isoFormat($local->year === $today->year ? 'dddd, D MMMM' : 'dddd, D MMMM YYYY')),
        };
    }

    /** Ziua și ora, în fusul celui care se uită. */
    public static function exact(CarbonInterface $at): string
    {
        return $at->copy()->setTimezone(auth()->user()?->tz() ?? config('app.timezone'))->locale(app()->getLocale())->isoFormat('dddd, D MMMM YYYY, HH:mm');
    }

    public static function short(CarbonInterface $at): string
    {
        $minutes = max(1, (int) floor($at->diffInMinutes(now(), true)));

        return match (true) {
            $minutes < 60 => __(':count min', ['count' => $minutes]),
            $minutes < 24 * 60 => __(':count h', ['count' => intdiv($minutes, 60)]),
            $minutes < 30 * 24 * 60 => trans_choice(':count day|:count days', intdiv($minutes, 24 * 60)),
            default => $at->copy()->locale(app()->getLocale())->isoFormat('D MMM'),
        };
    }

    /**
     * <x-wsa.time :at="…" /> fără mașinăria componentelor Blade (compilat așa de AppServiceProvider): pe o pagină sunt sute, iar componenta
     * costa ~0,5 ms fiecare. Același HTML ca resources/views/components/wsa/time.blade.php.
     */
    public static function tag(?CarbonInterface $at): HtmlString
    {
        if (! $at) {
            return new HtmlString('');
        }

        return new HtmlString('<time datetime="'.e($at->copy()->utc()->toIso8601String()).'" title="'.e(self::exact($at)).'">'.e($at->diffForHumans()).'</time>');
    }

    /** <x-wsa.new :at="…" :by="…" />, la fel: punctul pe ce au făcut alții de la ultima vizită (ca components/wsa/new.blade.php). */
    public static function dot(?CarbonInterface $at, ?int $by = null): HtmlString
    {
        $since = auth()->user()?->previous_visit_at;
        if (! $since || ! $at || ! $at->gt($since) || $by === auth()->id()) {
            return new HtmlString('');
        }
        $label = e(__('New since your last visit'));

        return new HtmlString('<span class="wsa-new-dot" title="'.$label.'"><span class="sr-only">'.$label.'</span></span>');
    }
}
