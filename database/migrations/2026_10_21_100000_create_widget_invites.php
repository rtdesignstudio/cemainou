<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitațiile la feedback pe site (un link, și QR-ul lui): refolosibile, cu durata aleasă (o dată, 7 / 30 de zile, până la oprire), oprite oricând.
 * Telefoanele intrate printr-o invitație o țin minte (widget_devices.invite_id): oprită sau expirată, ele nu mai trimit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 255);
            $table->string('token', 64)->unique();
            $table->boolean('single_use')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::table('widget_devices', function (Blueprint $table) {
            $table->foreignId('invite_id')->nullable()->constrained('widget_invites')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('widget_devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invite_id');
        });
        Schema::dropIfExists('widget_invites');
    }
};
