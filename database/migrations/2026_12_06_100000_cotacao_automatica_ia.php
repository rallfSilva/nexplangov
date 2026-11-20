<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotação automática por IA (art. 23, § 1º, I e II): preços já praticados em compras públicas, buscados no
 * Compras.gov.br e no PNCP, triados pela NexMind IA e gravados como cotações do item com a fonte e o motivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotacoes', function (Blueprint $table) {
            $table->string('origem', 10)->default('manual'); // manual | ia
            $table->decimal('quantidade', 18, 4)->nullable(); // quantidade da contratação de origem (peso da média ponderada)
            $table->string('unidade_fonte', 60)->nullable();
            $table->string('descricao_fonte', 1000)->nullable();
            $table->string('url_fonte', 500)->nullable();
            $table->string('analise_ia', 500)->nullable();
        });

        Schema::table('itens_processo', function (Blueprint $table) {
            $table->text('justificativa_precos')->nullable();
            $table->string('cotacao_ia_situacao', 20)->nullable(); // na_fila, cotado, sem_resultado, erro
            $table->string('cotacao_ia_mensagem', 500)->nullable();
            $table->timestamp('cotacao_ia_em')->nullable();
        });

        Schema::create('lotes_cotacao_ia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('escopo', 12); // sem_preco | todos
            $table->unsignedTinyInteger('resultados');
            $table->string('situacao', 12)->default('na_fila'); // na_fila, processando, concluido
            $table->unsignedInteger('total_itens');
            $table->unsignedInteger('itens_processados')->default(0);
            $table->unsignedInteger('itens_cotados')->default(0);
            $table->timestamp('concluido_em')->nullable();
            $table->timestamps();
            $table->index(['processo_id', 'situacao']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lotes_cotacao_ia');
        Schema::table('itens_processo', fn (Blueprint $t) => $t->dropColumn(['justificativa_precos', 'cotacao_ia_situacao', 'cotacao_ia_mensagem', 'cotacao_ia_em']));
        Schema::table('cotacoes', fn (Blueprint $t) => $t->dropColumn(['origem', 'quantidade', 'unidade_fonte', 'descricao_fonte', 'url_fonte', 'analise_ia']));
    }
};
