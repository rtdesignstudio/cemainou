<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// badge-urile: câte un rând pe treaptă câștigată (App\Models\Badge), „bravo"-urile colegilor și zilele care nu se pot reface din istoric
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);                          // streak, unblocker, whole_team... (App\Support\Badges\Catalog)
            $table->unsignedTinyInteger('tier')->default(1);    // 1-3
            $table->timestamp('earned_at');
            $table->json('context')->nullable();                // pentru ce: o sarcină, un coleg, o zi
            $table->boolean('backfilled')->default(false);      // câștigat din istoric, la pornire: intră în ecranul „Ai deja N badge-uri", nu în animații
            $table->timestamp('seen_at')->nullable();           // omul l-a văzut (animația sau ecranul de pornire)
            $table->timestamps();
            $table->unique(['user_id', 'key', 'tier']);
            $table->index(['user_id', 'earned_at']);
        });
        Schema::create('badge_kudos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('badge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();   // cine a dat „bravo"
            $table->string('note', 120)->nullable();
            $table->timestamps();
            $table->unique(['badge_id', 'user_id']);
        });
        Schema::create('badge_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);                          // zero_backlog: o zi fără restanțe, văzută în momentul ei
            $table->date('date');
            $table->unique(['user_id', 'key', 'date']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('badges_shared')->default(false);   // colegii îi văd badge-urile (pe pagina lui, pe Azi)
            $table->boolean('badges_hidden')->default(false);   // nu vrea badge-uri deloc: nu le vede, nu apar nicăieri
            $table->unsignedTinyInteger('evening_hour')->default(19);   // „Seara e a ta": ziua încheiată până la ora asta
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['badges_shared', 'badges_hidden', 'evening_hour']));
        Schema::dropIfExists('badge_marks');
        Schema::dropIfExists('badge_kudos');
        Schema::dropIfExists('badges');
    }
};
