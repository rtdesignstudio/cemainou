<?php

namespace App\Http\Controllers;

use App\Support\Telegram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** Webhook-ul botului de Telegram: doar cu antetul secret (pus de cemainou la setWebhook); orice altceva e 404. */
class TelegramController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(Telegram::configured() && hash_equals(Telegram::secret(), (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 404);
        try {
            Telegram::handle($request->json()->all());
        } catch (Throwable $e) {
            report($e);                                                     // mereu 200: altfel Telegram retrimite același mesaj la nesfârșit
        }

        return response()->json(['ok' => true]);
    }
}
