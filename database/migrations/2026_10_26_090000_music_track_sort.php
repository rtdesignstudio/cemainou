<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Muzica biroului: ordinea bibliotecii, trasă de mână în panou; după ea cântă biblioteca la rând. Pornește din ordinea de până acum (votate întâi, apoi cum au fost puse). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('music_tracks', function (Blueprint $table) {
            $table->unsignedInteger('sort')->nullable()->after('duration');
        });
        $ids = DB::table('music_tracks')->leftJoin('music_votes', 'music_votes.track_id', '=', 'music_tracks.id')
            ->groupBy('music_tracks.id')->orderByRaw('count(music_votes.id) desc')->orderBy('music_tracks.id')->pluck('music_tracks.id');
        foreach ($ids as $i => $id) {
            DB::table('music_tracks')->where('id', $id)->update(['sort' => $i + 1]);
        }
    }

    public function down(): void
    {
        Schema::table('music_tracks', function (Blueprint $table) {
            $table->dropColumn('sort');
        });
    }
};
