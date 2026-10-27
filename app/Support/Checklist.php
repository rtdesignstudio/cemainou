<?php

namespace App\Support;

/** Listele de verificat din detaliile unei sarcini („- [ ] pas"): câte sunt bifate și bifarea unui pas, după poziție. */
class Checklist
{
    private const LINE = '/^(\s*[-*+]\s+)\[( |x|X)\](\s+)/m';

    /** @return array{done: int, total: int}|null */
    public static function count(?string $text): ?array
    {
        if (! $text || ! preg_match_all(self::LINE, $text, $m)) {
            return null;
        }

        return ['done' => count(array_filter($m[2], fn (string $mark) => strtolower($mark) === 'x')), 'total' => count($m[2])];
    }

    /** @return list<array{n: int, text: string, done: bool}> pașii, numerotați de la 1, în ordinea din text */
    public static function steps(?string $text): array
    {
        if (! $text || ! preg_match_all('/^\s*[-*+]\s+\[( |x|X)\]\s+(.+)$/m', $text, $m, PREG_SET_ORDER)) {
            return [];
        }

        return array_map(fn (array $row, int $i) => ['n' => $i + 1, 'text' => trim($row[2]), 'done' => strtolower($row[1]) === 'x'], $m, array_keys($m));
    }

    /** Pasul cu numărul dat (de la 1) bifat sau debifat; textul rămâne neschimbat dacă pasul e deja așa sau nu există. */
    public static function set(string $text, int $n, bool $done): string
    {
        $steps = self::steps($text);
        if (! isset($steps[$n - 1]) || $steps[$n - 1]['done'] === $done) {
            return $text;
        }

        return self::toggle($text, $n - 1);
    }

    /** Toți pașii debifați (pentru următoarea dintr-o sarcină recurentă). */
    public static function reset(?string $text): ?string
    {
        return $text === null ? null : preg_replace(self::LINE, '$1[ ]$3', $text);
    }

    /** Bifează sau debifează pasul cu numărul dat (de la 0), în ordinea din text. */
    public static function toggle(string $text, int $index): string
    {
        $i = -1;

        return preg_replace_callback(self::LINE, function (array $m) use (&$i, $index) {
            $i++;

            return $i === $index ? $m[1].'['.(strtolower($m[2]) === 'x' ? ' ' : 'x').']'.$m[3] : $m[0];
        }, $text);
    }

    /** Pașii din HTML-ul randat, ca rânduri: căsuța, apoi textul într-un <span> (rândul al doilea nu mai trece pe sub căsuță); bifații marcați. */
    public static function rows(string $html): string
    {
        return preg_replace_callback('/<li>\s*(<input[^>]*type="checkbox"[^>]*>)(.*?)<\/li>/is', fn (array $m) => '<li class="wsa-step'.(str_contains($m[1], 'checked') ? ' is-done' : '').'">'.$m[1].'<span>'.trim($m[2]).'</span></li>', $html) ?? $html;
    }

    /** HTML-ul randat (Markdown, cu căsuțele dezactivate), cu căsuțe care bifează (Livewire), doar pentru cine are voie. */
    public static function interactive(string $html, string $method = 'toggleCheck'): string
    {
        $i = -1;

        return preg_replace_callback('/<input([^>]*)type="checkbox"([^>]*)>/i', function (array $m) use (&$i, $method) {
            $i++;
            $checked = str_contains($m[1].$m[2], 'checked');

            return '<input type="checkbox" class="wsa-check" wire:click="'.$method.'('.$i.')" wire:loading.attr="disabled"'.($checked ? ' checked' : '').'>';
        }, $html) ?? $html;
    }
}
