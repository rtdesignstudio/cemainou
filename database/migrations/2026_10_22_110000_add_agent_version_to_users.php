<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ce versiune a fișierelor agentului (hook-uri, /upd) are omul pe calculator, din ultima pornire de sesiune, și de câte porniri la rând
// e în urmă: actualizarea se face singură la pornire, deci în urmă de două ori la rând înseamnă că actualizarea nu merge acolo.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('agent_version', 20)->nullable();
            $table->timestamp('agent_version_at')->nullable();
            $table->unsignedSmallInteger('agent_behind')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['agent_version', 'agent_version_at', 'agent_behind']);
        });
    }
};
