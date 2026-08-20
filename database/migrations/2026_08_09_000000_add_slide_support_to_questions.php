<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soporte para diapositivas: `body` guarda el texto de la slide, y `time_limit`
 * pasa a nullable porque una slide no tiene temporizador (tampoco puntúa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table): void {
            $table->text('body')->nullable()->after('title');
            $table->unsignedSmallInteger('time_limit')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table): void {
            $table->dropColumn('body');
            $table->unsignedSmallInteger('time_limit')->nullable(false)->change();
        });
    }
};
