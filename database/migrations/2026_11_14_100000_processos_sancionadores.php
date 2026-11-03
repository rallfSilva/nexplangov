<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Processo de responsabilização de licitante ou contratado (arts. 155 a 163): instauração, intimação,
 * defesa, alegações finais, parecer jurídico, decisão com dosimetria, recurso ou reconsideração
 * (arts. 166 e 167), sanção definitiva, comunicação ao CEIS/CNEP (art. 161) e reabilitação (art. 163).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processos_sancionadores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('fornecedor_id')->constrained('fornecedores')->restrictOnDelete();
            $table->foreignId('contrato_id')->nullable()->constrained('contratos')->nullOnDelete();
            $table->foreignId('processo_id')->nullable()->constrained('processos')->nullOnDelete();
            $table->string('numero', 20);
            $table->json('infracoes');
            $table->text('fatos');
            $table->string('sancao_proposta', 20);
            $table->text('justificativa_agravamento')->nullable();
            $table->json('comissao')->nullable(); // ids de servidores (art. 158)
            $table->string('situacao', 20)->default('instaurado');
            $table->foreignId('instaurado_por')->nullable()->constrained('users')->nullOnDelete();
            // Intimação e defesa (arts. 157 e 158).
            $table->date('intimado_em')->nullable();
            $table->string('intimacao_meio', 200)->nullable();
            $table->string('intimacao_caminho')->nullable();
            $table->date('prazo_defesa')->nullable();
            $table->text('defesa')->nullable();
            $table->date('defesa_em')->nullable();
            $table->string('defesa_caminho')->nullable();
            $table->string('defesa_hash', 64)->nullable();
            $table->boolean('provas_requeridas')->default(false);
            $table->text('instrucao')->nullable();
            $table->date('prazo_alegacoes')->nullable();
            $table->text('alegacoes')->nullable();
            $table->date('alegacoes_em')->nullable();
            // Análise jurídica (obrigatória na inidoneidade, art. 156, § 6º).
            $table->text('parecer_juridico')->nullable();
            $table->foreignId('parecer_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('parecer_em')->nullable();
            // Decisão.
            $table->string('resultado', 12)->nullable(); // aplicada ou arquivado
            $table->string('sancao_tipo', 20)->nullable();
            $table->decimal('base_calculo', 16, 2)->nullable();
            $table->decimal('multa_valor', 16, 2)->nullable();
            $table->unsignedSmallInteger('duracao_meses')->nullable();
            $table->text('dosimetria')->nullable();
            $table->text('decisao')->nullable();
            $table->foreignId('decidido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->date('decidido_em')->nullable();
            $table->date('prazo_recurso')->nullable();
            $table->date('prazo_ceis')->nullable();
            // Recurso (art. 166) ou pedido de reconsideração (art. 167).
            $table->string('recurso_meio', 16)->nullable();
            $table->text('recurso_texto')->nullable();
            $table->date('recurso_em')->nullable();
            $table->date('prazo_reconsideracao')->nullable();
            $table->text('reconsideracao_texto')->nullable();
            $table->foreignId('reconsiderado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->date('prazo_decisao_recurso')->nullable();
            $table->string('recurso_resultado', 12)->nullable(); // provido, parcial, improvido
            $table->text('recurso_decisao')->nullable();
            $table->foreignId('recurso_decidido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->date('recurso_decidido_em')->nullable();
            // Efeitos.
            $table->date('definitivo_em')->nullable();
            $table->date('ceis_informado_em')->nullable();
            $table->string('ceis_protocolo', 80)->nullable();
            $table->date('reabilitado_em')->nullable();
            $table->text('reabilitacao_fundamento')->nullable();
            $table->timestamps();
            $table->unique(['orgao_id', 'numero']);
        });

        Schema::table('sancoes_fornecedor', function (Blueprint $table) {
            $table->foreignId('processo_sancionador_id')->nullable()->constrained('processos_sancionadores')->nullOnDelete();
            $table->decimal('multa_valor', 16, 2)->nullable();
            $table->string('abrangencia', 20)->nullable(); // ente (impedimento) ou nacional (inidoneidade)
        });
    }

    public function down(): void
    {
        Schema::table('sancoes_fornecedor', function (Blueprint $table) {
            $table->dropConstrainedForeignId('processo_sancionador_id');
            $table->dropColumn(['multa_valor', 'abrangencia']);
        });
        Schema::dropIfExists('processos_sancionadores');
    }
};
