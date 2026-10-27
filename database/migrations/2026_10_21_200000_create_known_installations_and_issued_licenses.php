<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doar la RT (casa-mamă): instalările care s-au făcut cunoscute singure („Ține-mă la curent" din Setările lor) și cheile de licență emise
 * (adăugate lipind cheia: datele se citesc din ea, verificată cu cheia publică). Instanțele nu trimit nimic altfel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('known_installations', function (Blueprint $table) {
            $table->id();
            $table->uuid('installation')->unique();
            $table->string('email', 255);
            $table->string('company', 255)->nullable();
            $table->string('name', 255)->nullable();
            $table->string('version', 20)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();
        });
        Schema::create('issued_licenses', function (Blueprint $table) {
            $table->id();
            $table->string('license_id', 40)->nullable();
            $table->string('licensee', 255);
            $table->string('installation', 64)->nullable();
            $table->unsignedInteger('people')->nullable();
            $table->date('until');
            $table->string('key_hash', 64)->unique();
            $table->string('note', 500)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_licenses');
        Schema::dropIfExists('known_installations');
    }
};
