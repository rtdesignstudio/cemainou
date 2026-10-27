<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback despre cemainou, trimis din butonul din dreapta jos: textul, pagina (link), ecranul, browserul, versiunea și fișierele (capturi, video, PDF,
 * în data/files/feedback/). Adminul îl vede în „Feedback", deschis sau rezolvat, cu ce s-a făcut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->text('body');
            $table->string('url', 2000)->nullable();
            $table->string('viewport', 40)->nullable();
            $table->string('screen', 60)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->json('files')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
