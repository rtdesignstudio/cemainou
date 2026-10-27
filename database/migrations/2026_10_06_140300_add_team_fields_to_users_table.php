<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('member')->after('email');          // Role
            $table->boolean('is_active')->default(true)->after('role');
            $table->string('timezone')->default('Europe/Chisinau')->after('is_active');
            $table->string('focus_text')->nullable();                            // „acum lucrez la..."
            $table->foreignId('focus_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->timestamp('focus_updated_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('focus_project_id');
            $table->dropColumn(['role', 'is_active', 'timezone', 'focus_text', 'focus_updated_at', 'last_seen_at']);
        });
    }
};
