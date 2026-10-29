<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Jurnalul accesului din afară (App\Support\AccessGuard): ce au deschis clienții și colaboratorii, ce li s-a oprit și ce au încercat fără să existe. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('outcome', 16);                                      // ok, breach, blocked, denied
            $table->string('via', 16);                                          // web, file, agent, notice, email, digest, report
            $table->string('subject_type', 16)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('reason', 32)->nullable();
            $table->string('path', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['outcome', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_events');
    }
};
