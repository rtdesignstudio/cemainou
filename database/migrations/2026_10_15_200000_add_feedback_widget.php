<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// widget-ul de feedback pe site-ul clientului: pornit pe proiect, pe domeniile permise, vizibil doar pe dispozitivele activate de oamenii cemainou
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('widget_enabled')->default(false);
            $table->json('widget_domains')->nullable();
            $table->string('widget_key', 40)->nullable()->unique();
        });
        Schema::create('widget_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('domain', 255);
            $table->string('device', 120)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_devices');
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn(['widget_enabled', 'widget_domains', 'widget_key']));
    }
};
