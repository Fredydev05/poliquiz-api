<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dominio de cuestionarios: quizzes → questions → question_options.
 * El contenido "congelado" que se juega vive en games.quiz_snapshot (F2);
 * estas tablas son la fuente editable del docente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('description', 500)->nullable();
            $table->string('visibility', 10)->default('private'); // private | public
            $table->string('cover_path')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('visibility');
        });

        Schema::create('questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('kind', 20); // App\Enums\QuestionKind
            $table->string('title', 250);
            $table->unsignedSmallInteger('time_limit'); // segundos (5..240)
            $table->string('points_mode', 10)->default('standard'); // App\Enums\PointsMode
            $table->string('image_path')->nullable();
            $table->timestamps();

            $table->unique(['quiz_id', 'position']);
        });

        Schema::create('question_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('text', 120);
            $table->boolean('is_correct')->default(false);

            $table->unique(['question_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('quizzes');
    }
};
