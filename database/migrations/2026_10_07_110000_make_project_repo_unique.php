<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // dacă două proiecte au același repo, îl păstrează cel mai vechi; celorlalte li se golește doar cheia
        // (adresa rămâne, iar formularul le cere un repo diferit la următoarea salvare)
        $duplicates = DB::table('projects')->whereNotNull('repo_key')->groupBy('repo_key')->havingRaw('count(*) > 1')->pluck('repo_key');
        foreach ($duplicates as $key) {
            $keep = DB::table('projects')->where('repo_key', $key)->min('id');
            DB::table('projects')->where('repo_key', $key)->where('id', '!=', $keep)->update(['repo_key' => null]);
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['repo_key']);
            $table->unique('repo_key');                          // un repo aparține unui singur proiect
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['repo_key']);
            $table->index('repo_key');
        });
    }
};
