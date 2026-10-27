<?php

namespace App\Http\Middleware;

use App\Support\Channels;
use App\Support\Housekeeping;
use App\Support\Installer;
use App\Support\MorningSummary;
use App\Support\Outbox;
use App\Support\WeeklyDigest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Curățenia zilei și rezumatul săptămânal rulează după ce răspunsul a plecat la om, la prima cerere de după oră - așa nu e nevoie de cron pe un hosting obișnuit. */
class RunHousekeeping
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (app()->runningUnitTests() || ! Installer::installed() || app()->bound('cemainou.code_replaced')) {   // cod nou pus în cererea asta: nimic din cel vechi după
            return;                                          // în teste, curățenia se cheamă direct (HousekeepingTest); neinstalat, n-are ce curăța
        }

        try {
            app(Housekeeping::class)->runIfDue();
        } catch (Throwable $e) {
            report($e);                                      // curățenia nu strică niciodată o cerere
        }
        try {
            Outbox::flushIfDue();                                // emailurile despre sarcini care și-au așteptat rândul
        } catch (Throwable $e) {
            report($e);
        }
        try {
            MorningSummary::sendIfDue();         // rezumatul de dimineață, după ora 8 în fusul fiecăruia
        } catch (Throwable $e) {
            report($e);
        }
        try {
            Channels::digestsIfDue();                        // în canalul echipei: rezumatul de dimineață și săptămâna
        } catch (Throwable $e) {
            report($e);
        }
        try {
            app(WeeklyDigest::class)->sendIfDue();           // rezumatul săptămânal, în ziua și la ora din Setări
        } catch (Throwable $e) {
            report($e);
        }
    }
}
