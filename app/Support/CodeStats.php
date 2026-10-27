<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * Commit-urile și rândurile de cod ale unei zile, cum le numără agentul din git la /upd (doar ale omului, fără merge-uri și fără fișierele
 * generate). Sunt informație, nu măsură: nuanța din grilă vine din timp; pentru colegi, commit-urile se adaugă la contribuții cu o limită.
 */
class CodeStats
{
    /** Cât contează commit-urile unei zile în contribuțiile din grilă: cel mult atât, ca să nu domine. */
    public const MAX_CONTRIBUTION = 5;

    /** Totalul update-urilor date (o zi): null dacă niciunul n-are commit-uri. @return array{commits: int, added: int, removed: int}|null */
    public static function sum(Collection $updates): ?array
    {
        $commits = (int) $updates->sum('commits');

        return $commits > 0 ? ['commits' => $commits, 'added' => (int) $updates->sum('lines_added'), 'removed' => (int) $updates->sum('lines_removed')] : null;
    }

    /** „12 commits · +840 / −120 lines" */
    public static function label(array $stats): string
    {
        $number = fn (int $n) => Number::format($n, locale: app()->getLocale());

        return trans_choice('{1} :count commit|[2,*] :count commits', $stats['commits'], ['count' => $number($stats['commits'])])
            .' · '.__('+:added / −:removed lines', ['added' => $number($stats['added']), 'removed' => $number($stats['removed'])]);
    }
}
