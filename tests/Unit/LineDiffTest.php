<?php

namespace Tests\Unit;

use App\Support\LineDiff;
use PHPUnit\Framework\TestCase;

/** Ce s-a schimbat între două versiuni, pe rânduri. */
class LineDiffTest extends TestCase
{
    public function test_changed_added_and_removed_lines(): void
    {
        $ops = LineDiff::lines("titlu\nbuget 1000\ntermen iunie\nfinal", "titlu\nbuget 1500\ntermen iunie\nfinal\nlogo");

        $this->assertSame([[' ', 'titlu'], ['-', 'buget 1000'], ['+', 'buget 1500'], [' ', 'termen iunie'], [' ', 'final'], ['+', 'logo']], $ops);
        $this->assertSame(['added' => 2, 'removed' => 1], LineDiff::stats($ops));
    }

    public function test_unchanged_lines_far_from_changes_are_folded(): void
    {
        $old = implode("\n", range(1, 20));
        $new = str_replace("\n10\n", "\nzece\n", $old);

        $hunks = LineDiff::hunks(LineDiff::lines($old, $new), 2);
        $this->assertSame([['…', 7], [' ', '8'], [' ', '9'], ['-', '10'], ['+', 'zece'], [' ', '11'], [' ', '12'], ['…', 8]], $hunks);
        $this->assertSame([], LineDiff::hunks(LineDiff::lines($old, $old)));
    }

    public function test_from_and_to_an_empty_text(): void
    {
        $this->assertSame([['+', 'a'], ['+', 'b']], LineDiff::lines('', "a\nb"));
        $this->assertSame([['-', 'a']], LineDiff::lines('a', ''));
    }

    public function test_a_huge_rewrite_shows_as_replaced_instead_of_keeping_the_server_busy(): void
    {
        $old = implode("\n", array_map(fn ($i) => "vechi {$i}", range(1, 700)));
        $new = implode("\n", array_map(fn ($i) => "nou {$i}", range(1, 700)));

        $ops = LineDiff::lines($old, $new);
        $this->assertSame(['added' => 700, 'removed' => 700], LineDiff::stats($ops));
        $this->assertSame('-', $ops[0][0]);
        $this->assertSame('+', $ops[700][0]);
    }
}
