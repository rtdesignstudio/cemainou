<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// un rând „Blocat" sau „Urmează" dintr-un update se poate marca rezolvat (cine, când, cu ce notă): iese din blocaje și din ce urmează
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('update_items', function (Blueprint $table) {
            $table->timestamp('resolved_at')->nullable()->after('position');
            $table->foreignId('resolved_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
            $table->string('resolution', 300)->nullable()->after('resolved_by');
        });
    }

    public function down(): void
    {
        Schema::table('update_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['resolved_at', 'resolution']);
        });
    }
};
