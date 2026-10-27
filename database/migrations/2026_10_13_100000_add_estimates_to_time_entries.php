<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Orele estimate (din orele commit-urilor, la /upd, pentru zilele fără timp măsurat): separat de cele adăugate de om, legate de update-ul zilei. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->boolean('is_estimate')->default(false)->after('minutes');
            $table->foreignId('update_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('update_id');
            $table->dropColumn('is_estimate');
        });
    }
};
