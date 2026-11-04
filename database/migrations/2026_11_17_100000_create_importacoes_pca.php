<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico das importações de planilha do PCA (art. 12, VII): cada arquivo passa por pré-visualização,
 * com erros e duplicidades apontados por linha, antes de virar item do plano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importacoes_pca', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('plano_contratacao_id')->constrained('planos_contratacao')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('arquivo_nome', 160);
            $table->char('arquivo_hash', 64);
            $table->string('situacao', 20)->default('pre_visualizacao'); // pre_visualizacao, confirmada, cancelada
            $table->json('linhas');
            $table->unsignedInteger('total_linhas')->default(0);
            $table->unsignedInteger('validas')->default(0);
            $table->unsignedInteger('com_erro')->default(0);
            $table->unsignedInteger('duplicadas')->default(0);
            $table->unsignedInteger('importadas')->default(0);
            $table->timestamp('confirmada_em')->nullable();
            $table->timestamps();
            $table->index(['plano_contratacao_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importacoes_pca');
    }
};
