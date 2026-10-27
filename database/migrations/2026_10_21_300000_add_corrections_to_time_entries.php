<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// „Corectează timpul": diferența dintre cât a lucrat omul de fapt într-o zi, pe un proiect, și ce s-a măsurat (poate fi negativă).
// Măsurătoarea rămâne neatinsă; corecția se vede separat, cu motivul în note.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->boolean('is_correction')->default(false)->after('is_estimate');
            $table->foreignId('corrected_by')->nullable()->after('is_correction')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('corrected_by');
            $table->dropColumn('is_correction');
        });
    }
};
