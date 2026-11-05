<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duplicação de processo (reaproveitar a instrução de contratação deserta, fracassada ou recorrente) e anotações
 * internas de rascunho entre os servidores, que não entram nos autos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processos', function (Blueprint $table) {
            $table->foreignId('origem_id')->nullable()->after('demanda_id')->constrained('processos')->nullOnDelete();
        });

        Schema::create('anotacoes_processo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('texto');
            $table->timestamps();
            $table->index(['processo_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anotacoes_processo');
        Schema::table('processos', fn (Blueprint $table) => $table->dropConstrainedForeignId('origem_id'));
    }
};
