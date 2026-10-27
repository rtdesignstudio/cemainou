<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentele unui proiect (Markdown, cu istoric: fiecare salvare e o versiune, cu autor) și atașamentele lui (fișiere în data/files, linkuri externe).
 * Pentru proiectele fără git: brief, concept, decizii, stare; prezentări, PDF-uri, machete; Figma, Drive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('slug', 140);
            $table->longText('body');                                         // fără valoare implicită: MySQL n-o acceptă pe TEXT; modelul pune ''
            $table->unsignedInteger('version')->default(0);                 // 0 = creat, încă nescris
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();      // cine l-a creat
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'slug']);
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title', 120);
            $table->longText('body');
            $table->string('note', 200)->nullable();                        // ce s-a schimbat, în cuvintele autorului
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20)->default('web');
            $table->timestamp('created_at')->nullable();
            $table->unique(['document_id', 'version']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10);                                     // file / link
            $table->string('title', 160);
            $table->string('url', 2000)->nullable();                        // link
            $table->string('path')->nullable();                             // fișier, relativ la data/files
            $table->string('name')->nullable();                             // numele original al fișierului
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('source', 20)->default('web');
            $table->timestamps();
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
    }
};
