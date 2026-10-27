<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Muzica biroului pe YouTube: piesele (și istoricul echipei: când au cântat, cine le-a pus prima dată, excluse, blocate pentru redare
 * în alte site-uri) și coada. Tabelul music_picks (încercarea cu Spotify) nu mai e folosit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('music_tracks', function (Blueprint $table) {
            $table->id();
            $table->string('video_id', 20)->unique();
            $table->string('title', 255);
            $table->string('author', 255)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('plays')->default(0);
            $table->timestamp('last_played_at')->nullable();
            $table->timestamp('excluded_until')->nullable();
            $table->boolean('blocked')->default(false);
            $table->timestamps();
        });
        Schema::create('music_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained('music_tracks')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->double('sort')->index();                                                // ordinea; „Piesa anterioară" pune ce cânta înaintea tuturor
            $table->timestamp('created_at')->nullable();
        });
        Schema::dropIfExists('music_picks');
    }

    public function down(): void
    {
        Schema::dropIfExists('music_queue');
        Schema::dropIfExists('music_tracks');
    }
};
