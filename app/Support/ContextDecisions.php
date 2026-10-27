<?php

namespace App\Support;

use App\Enums\Source;
use App\Exceptions\DocumentConflict;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;

/**
 * Deciziile din update-uri, în contextul proiectului (/ctx): câte un rând „- AAAA-LL-ZZ textul" sub „## Decisions", după ultima decizie datată.
 * Restul documentului nu se atinge; o decizie care e deja acolo nu se mai adaugă. Fiecare adăugare e o versiune nouă, cu nota ei.
 */
class ContextDecisions
{
    /** E decizia deja în context? (același text, fără Markdown, litere mici, spațiile strânse) */
    public static function contains(?Document $context, string $text): bool
    {
        return $context !== null && str_contains(self::normalize($context->body), self::normalize($text));
    }

    /**
     * @param  list<array{date: string, text: string}>  $decisions
     * @return array{added: int, version: int}|null null când proiectul nu are încă context
     */
    public static function add(Project $project, User $by, array $decisions, Source $source = Source::Web, ?string $note = null): ?array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {                                  // un coleg a salvat între timp: încă o dată, pe versiunea lui
            $context = $project->contextDocument();
            if (! $context) {
                return null;
            }
            $new = collect($decisions)->map(fn (array $d) => ['date' => $d['date'], 'text' => trim(preg_replace('/\s+/u', ' ', $d['text']))])
                ->filter(fn (array $d) => $d['text'] !== '' && ! self::contains($context, $d['text']))->unique('text')->values();
            if ($new->isEmpty()) {
                return ['added' => 0, 'version' => $context->version];
            }
            try {
                $context->write($by, self::insert($context->body, $new->map(fn (array $d) => "- {$d['date']} {$d['text']}")->all()), $context->version, $source,
                    $note ?? trans_choice('Added :count decision from an update|Added :count decisions from an update', $new->count(), [], Settings::get('locale')));

                return ['added' => $new->count(), 'version' => $context->refresh()->version];
            } catch (DocumentConflict) {
                continue;
            }
        }

        return null;
    }

    /** Rândurile noi, după ultima decizie datată din secțiune (sau la începutul ei); fără secțiune, una nouă la sfârșit. */
    public static function insert(string $body, array $lines): string
    {
        $rows = explode("\n", str_replace("\r\n", "\n", rtrim($body)));
        $start = null;
        foreach ($rows as $i => $row) {
            if (preg_match('/^##\s+(decisions|decizii|deciziile)\b/iu', trim($row))) {
                $start = $i;
                break;
            }
        }
        if ($start === null) {
            return rtrim($body)."\n\n## ".__('Decisions', [], Settings::get('locale'))."\n".implode("\n", $lines)."\n";
        }
        $end = count($rows);
        for ($i = $start + 1; $i < count($rows); $i++) {
            if (preg_match('/^#{1,2}\s/', $rows[$i])) {
                $end = $i;
                break;
            }
        }
        $at = $start + 1;
        for ($i = $start + 1; $i < $end; $i++) {
            if (preg_match('/^\s*[-*]\s+\d{4}-\d{2}-\d{2}\b/', $rows[$i])) {
                $at = $i + 1;
                while ($at < $end && preg_match('/^\s{2,}\S/', $rows[$at])) {   // rândurile de continuare ale aceleiași decizii
                    $at++;
                }
            }
        }
        array_splice($rows, $at, 0, $lines);

        return implode("\n", $rows)."\n";
    }

    private static function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(Markdown::plain($text))));
    }
}
