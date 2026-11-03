<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medição, liquidação e pagamento na ordem cronológica (arts. 140 e 141). Medição e liquidação
 * não se apagam: a correção é feita por recusa ou cancelamento motivado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('contrato_id')->constrained('contratos')->restrictOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->date('periodo_inicio');
            $table->date('periodo_fim');
            $table->decimal('valor', 16, 2);
            $table->text('descricao'); // serviços executados, etapas da obra, quantidades medidas
            $table->string('situacao', 12)->default('pendente'); // pendente, aprovada, recusada
            $table->foreignId('medido_por')->constrained('users')->restrictOnDelete();
            $table->foreignId('aprovado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprovado_em')->nullable();
            $table->string('motivo_recusa', 500)->nullable();
            $table->timestamps();
            $table->unique(['contrato_id', 'numero']);
        });

        Schema::create('liquidacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('fornecedor_id')->constrained('fornecedores')->restrictOnDelete();
            $table->foreignId('contrato_id')->nullable()->constrained('contratos')->restrictOnDelete();
            $table->foreignId('medicao_id')->nullable()->unique()->constrained('medicoes')->restrictOnDelete();
            $table->foreignId('ordem_id')->nullable()->constrained('ordens_fornecimento')->restrictOnDelete();
            $table->string('categoria', 14); // fornecimento, locacao, servicos, obras (art. 141, I a IV)
            $table->string('fonte_recurso', 120);
            $table->string('nota_fiscal', 60);
            $table->date('nota_emitida_em');
            $table->decimal('valor_bruto', 16, 2);
            $table->decimal('retencoes', 16, 2)->default(0);
            $table->decimal('valor_liquido', 16, 2);
            $table->text('atesto');
            $table->date('liquidado_em'); // marca a entrada na fila
            $table->foreignId('liquidado_por')->constrained('users')->restrictOnDelete();
            $table->date('vencimento');
            $table->string('situacao', 12)->default('liquidado'); // liquidado, pago, cancelado
            $table->date('pago_em')->nullable();
            $table->string('ordem_bancaria', 60)->nullable();
            $table->foreignId('pago_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_cancelamento', 500)->nullable();
            // Alteração da ordem (art. 141, § 1º).
            $table->string('quebra_hipotese', 4)->nullable();
            $table->text('quebra_justificativa')->nullable();
            $table->foreignId('quebra_autorizada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('quebra_autorizada_em')->nullable();
            $table->date('comunicado_controle_em')->nullable();
            $table->date('comunicado_tribunal_em')->nullable();
            $table->timestamps();
            $table->index(['orgao_id', 'fonte_recurso', 'categoria', 'situacao', 'liquidado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidacoes');
        Schema::dropIfExists('medicoes');
    }
};
