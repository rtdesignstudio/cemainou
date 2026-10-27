<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Un proiect poate fi de mai multe feluri (design și dezvoltare, de exemplu): categories, o listă; vechea category se mută în ea și rămâne nefolosită. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->json('categories')->nullable()->after('category'));
        DB::table('projects')->whereNotNull('category')->orderBy('id')->each(function ($project) {
            DB::table('projects')->where('id', $project->id)->update(['categories' => json_encode([$project->category])]);
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('categories'));
    }
};
