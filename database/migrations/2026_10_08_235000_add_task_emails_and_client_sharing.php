<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emailurile despre sarcini (clientul află când sarcina lui e gata sau i se răspunde; liderul, când clientul scrie) și ce vede clientul din
 * fișierele și documentele proiectului: doar ce echipa bifează „Vizibil clientului".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('wants_task_emails')->default(true)->after('wants_digest'));
        Schema::table('attachments', fn (Blueprint $table) => $table->boolean('client_visible')->default(false)->after('task_id'));
        Schema::table('documents', fn (Blueprint $table) => $table->boolean('client_visible')->default(false)->after('project_id'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('wants_task_emails'));
        Schema::table('attachments', fn (Blueprint $table) => $table->dropColumn('client_visible'));
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn('client_visible'));
    }
};
