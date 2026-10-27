<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Aceeași limitare ca `throttle`, dar rulată înaintea autentificării (lista de priorități din bootstrap/app.php): zgomotul fără cheie
 * nu ocupă serverul. Limitarea obișnuită, pe om, rămâne după autentificare.
 */
class ThrottleBeforeAuth extends ThrottleRequests
{
    //
}
