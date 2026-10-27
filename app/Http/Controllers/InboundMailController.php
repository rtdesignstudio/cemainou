<?php

namespace App\Http\Controllers;

use App\Support\InboundMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Webhook-ul pentru emailul care devine sarcină: emailul brut în corp (sau JSON {raw}), cu cheia din adresă (InboundMail::token). */
class InboundMailController extends Controller
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        abort_unless(hash_equals(InboundMail::token(), $token), 404);
        $raw = $request->isJson() ? (string) $request->json('raw') : (string) $request->getContent();
        $task = InboundMail::receive($raw);

        return response()->json(['ok' => true, 'task' => $task?->id]);   // la fel și când e ignorat: expeditorul nu află ce adrese sau conturi există
    }
}
