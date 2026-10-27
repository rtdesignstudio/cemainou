<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Linkul invitației, criptat cu cheia aplicației (APP_KEY): adminul îl poate vedea din nou cât e valabil; se șterge când omul intră. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('invite_link')->nullable()->after('invite_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('invite_link'));
    }
};
