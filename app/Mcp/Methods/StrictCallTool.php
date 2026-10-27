<?php

namespace App\Mcp\Methods;

use App\Mcp\Tools\TeamTool;
use Generator;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * Apelul unei unelte, cu o verificare în plus: un câmp pe care unealta nu-l cunoaște (un nume greșit, ca „decision" în loc de „decisions")
 * oprește apelul cu un mesaj clar, în loc să fie ignorat în tăcere și să se piardă ce a vrut omul să trimită. Nu se salvează nimic.
 */
class StrictCallTool extends CallTool
{
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $tool = $context->tools()->first(fn ($tool): bool => $tool->name() === ($request->params['name'] ?? null));
        $arguments = $request->params['arguments'] ?? [];
        if ($tool && is_array($arguments)) {
            $known = array_keys((array) ($tool->toArray()['inputSchema']['properties'] ?? []));
            $legacy = $tool instanceof TeamTool ? $tool->legacyFields : [];             // nume vechi, încă primite de la agenții cu definiția veche
            $unknown = array_values(array_diff(array_keys($arguments), $known, $legacy, ['session']));   // session: instrucțiunile îi cer agentului s-o trimită peste tot; unde nu folosește, se ignoră
            if ($unknown) {
                return JsonRpcResponse::result($request->id, [
                    'content' => [['type' => 'text', 'text' => $this->message($tool->name(), $unknown, $known)]],
                    'isError' => true,
                ]);
            }
        }

        return parent::handle($request, $context);
    }

    /** @param  list<string>  $unknown @param  list<string>  $known */
    private function message(string $tool, array $unknown, array $known): string
    {
        $hints = collect($unknown)->map(function (string $field) use ($known) {
            $close = collect($known)->sortBy(fn (string $k) => levenshtein($field, $k))->first();

            return $close !== null && levenshtein($field, $close) <= 3 ? "\"{$field}\" (did you mean \"{$close}\"?)" : "\"{$field}\"";
        })->join(', ');

        return "{$tool} does not know the field(s) {$hints}. Nothing was saved. Known fields: ".implode(', ', $known).'. Send it again with the right names.';
    }
}
