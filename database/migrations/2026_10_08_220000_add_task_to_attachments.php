<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Atașamente și pe sarcini (o captură, un PDF, un link): același tabel, cu sarcina; pe proiect rămân cele fără sarcină. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->foreignId('task_id')->nullable()->after('project_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attachments', fn (Blueprint $table) => $table->dropConstrainedForeignId('task_id'));
    }
};
