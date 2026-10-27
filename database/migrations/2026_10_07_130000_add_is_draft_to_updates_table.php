<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ciornele automate (/upd-auto): private până le trimite omul; una pe om și proiect
        Schema::table('updates', function (Blueprint $table) {
            $table->boolean('is_draft')->default(false)->after('source');
            $table->index(['user_id', 'is_draft']);
        });
    }

    public function down(): void
    {
        Schema::table('updates', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_draft']);
            $table->dropColumn('is_draft');
        });
    }
};
