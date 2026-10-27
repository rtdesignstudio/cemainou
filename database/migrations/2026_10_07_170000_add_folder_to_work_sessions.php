<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proiectul se leagă de sesiune, nu de folder: amprenta folderului (sha256 al căii, calculat la om; calea nu pleacă) și de unde vine
 * proiectul sesiunii (person = ales de om cu use_project, folder = moștenit din alegerea de data trecută din același folder, file, repo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_sessions', function (Blueprint $table) {
            $table->string('folder_key', 64)->nullable()->after('agent_session_id');
            $table->string('project_source', 16)->nullable()->after('project_id');
            $table->index(['user_id', 'folder_key']);
        });
    }

    public function down(): void
    {
        Schema::table('work_sessions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'folder_key']);
            $table->dropColumn(['folder_key', 'project_source']);
        });
    }
};
