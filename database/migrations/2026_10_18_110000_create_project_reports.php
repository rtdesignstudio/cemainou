<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Raportul lunar al unui proiect, de trimis clientului: o pagină la un link greu de ghicit (/r/{token}), retras oricând. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7);                          // 2026-10
            $table->string('token', 48)->unique();
            $table->boolean('include_hours')->default(false);
            $table->text('intro')->nullable();
            $table->string('tz', 64)->default('Europe/Chisinau');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();          // prima deschidere a linkului
            $table->timestamps();
            $table->unique(['project_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_reports');
    }
};
