<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dominio de partidas (plan §3). El estado EN VIVO vive en Redis;
 * estas tablas guardan lo permanente: la partida, su snapshot del quiz
 * y (al terminar, F6) los resultados completos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_id')->nullable()->constrained()->nullOnDelete();
            // Snapshot congelado al crear la partida: editar el quiz después
            // no afecta partidas en curso ni reportes históricos.
            $table->jsonb('quiz_snapshot');
            $table->char('pin', 6)->nullable();
            $table->string('status', 10)->default('active'); // active | ended | aborted
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        // PIN único SOLO entre partidas activas (reutilizable después).
        DB::statement("CREATE UNIQUE INDEX games_pin_active_unique ON games (pin) WHERE status = 'active'");

        Schema::create('game_players', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('game_id')->constrained()->cascadeOnDelete();
            $table->uuid('player_uuid');
            $table->string('nickname', 20);
            $table->integer('final_score')->default(0);
            $table->unsignedSmallInteger('final_rank')->nullable();
            $table->unsignedSmallInteger('answers_correct')->default(0);

            $table->unique(['game_id', 'player_uuid']);
        });

        Schema::create('player_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_player_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('question_index');
            $table->jsonb('selected'); // índices/ids de opciones elegidas
            $table->unsignedInteger('response_ms');
            $table->boolean('is_correct');
            $table->integer('points');
            $table->unsignedSmallInteger('streak_after');

            $table->index('game_player_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_answers');
        Schema::dropIfExists('game_players');
        Schema::dropIfExists('games');
    }
};
