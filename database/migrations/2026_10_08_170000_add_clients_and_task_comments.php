<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clienții în aplicație: un cont cu rolul „client" e legat de firma lui și vede doar proiectele ei pornite ca „vizibil clientului"
 * (sarcinile, blocajele, update-urile care nu sunt interne). Plus comentariile pe sarcini (clarificări, și cu clientul).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('role')->constrained()->nullOnDelete();
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('client_visible')->default(false)->after('client_id');
        });
        Schema::table('updates', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false)->after('summary');
        });
        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->string('source', 20)->default('web');
            $table->timestamps();
            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comments');
        Schema::table('updates', fn (Blueprint $table) => $table->dropColumn('is_internal'));
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('client_visible'));
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('client_id'));
    }
};
