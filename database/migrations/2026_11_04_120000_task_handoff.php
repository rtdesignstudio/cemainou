<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Predarea unei sarcini: comentariul de predare (ce e făcut, ce rămâne, fișierul final) și fișierele marcate ca versiuni vechi (nu se șterg). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_comments', function (Blueprint $table) {
            $table->boolean('handoff')->default(false)->after('body');
        });
        Schema::table('attachments', function (Blueprint $table) {
            $table->timestamp('superseded_at')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('task_comments', fn (Blueprint $table) => $table->dropColumn('handoff'));
        Schema::table('attachments', fn (Blueprint $table) => $table->dropColumn('superseded_at'));
    }
};
