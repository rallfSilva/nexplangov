<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Atas de registro de preços (arts. 82 a 86).
        Schema::create('atas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('processo_id')->constrained('processos')->restrictOnDelete();
            $table->foreignId('fornecedor_id')->constrained('fornecedores')->restrictOnDelete();
            $table->foreignId('criada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('ano');
            $table->string('numero', 10);
            $table->date('assinatura');
            $table->date('vigencia_inicio');
            $table->date('vigencia_fim');
            $table->boolean('prorrogada')->default(false);
            $table->text('fundamento_prorrogacao')->nullable();
            $table->string('situacao', 12)->default('vigente'); // vigente, cancelada
            $table->string('motivo_cancelamento', 500)->nullable();
            $table->timestamps();
            $table->unique(['orgao_id', 'ano', 'numero']);
        });

        Schema::create('ata_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('ata_id')->constrained('atas')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('itens_processo')->restrictOnDelete();
            $table->decimal('quantidade_registrada', 16, 4);
            $table->decimal('valor_unitario', 16, 4);
            $table->timestamps();
            $table->unique(['ata_id', 'item_id']);
        });

        Schema::create('ata_adesoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('ata_item_id')->constrained('ata_itens')->cascadeOnDelete();
            $table->string('aderente_nome', 200);
            $table->string('aderente_cnpj', 18);
            $table->decimal('quantidade', 16, 4);
            $table->text('justificativa');
            $table->text('vantagem'); // art. 86, § 2º: vantagem e compatibilidade com o mercado
            $table->string('situacao', 12)->default('solicitada'); // solicitada, autorizada, negada
            $table->string('motivo_decisao', 500)->nullable();
            $table->foreignId('decidida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decidida_em')->nullable();
            $table->foreignId('solicitada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ata_consumos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('ata_item_id')->constrained('ata_itens')->cascadeOnDelete();
            $table->foreignId('adesao_id')->nullable()->constrained('ata_adesoes')->nullOnDelete();
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->nullOnDelete();
            $table->decimal('quantidade', 16, 4);
            $table->string('referencia', 200); // empenho, ordem de fornecimento ou contrato
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // Reequilíbrio econômico-financeiro (art. 124, II, d).
        Schema::create('reequilibrios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('contrato_id')->constrained('contratos')->cascadeOnDelete();
            $table->string('fundamento', 30);
            $table->text('motivo');
            $table->decimal('valor_pedido', 16, 2);
            $table->decimal('valor_concedido', 16, 2)->nullable();
            $table->string('situacao', 12)->default('solicitado'); // solicitado, deferido, indeferido
            $table->date('solicitado_em');
            $table->date('decidido_em')->nullable();
            $table->text('parecer')->nullable();
            $table->foreignId('termo_id')->nullable()->constrained('termos_contrato')->nullOnDelete();
            $table->foreignId('solicitado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decidido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Extinção do contrato (arts. 137 a 139).
        Schema::create('rescisoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('contrato_id')->constrained('contratos')->cascadeOnDelete();
            $table->string('modalidade', 12); // unilateral, consensual, judicial
            $table->string('motivo', 40);
            $table->text('fundamentacao');
            $table->date('notificado_em')->nullable();
            $table->date('defesa_ate')->nullable();
            $table->text('defesa')->nullable();
            $table->date('defesa_recebida_em')->nullable();
            $table->date('data_efeito')->nullable();
            $table->boolean('culpa_administracao')->default(false);
            $table->decimal('valor_indenizacao', 16, 2)->nullable();
            $table->string('situacao', 12)->default('iniciada'); // iniciada, concluida, cancelada
            $table->foreignId('termo_id')->nullable()->constrained('termos_contrato')->nullOnDelete();
            $table->foreignId('iniciada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('concluida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('concluida_em')->nullable();
            $table->timestamps();
        });

        Schema::table('termos_contrato', function (Blueprint $table) {
            $table->boolean('reequilibrio')->default(false); // não entra no limite do art. 125 (só alterações unilaterais)
        });
    }

    public function down(): void
    {
        Schema::table('termos_contrato', fn (Blueprint $t) => $t->dropColumn('reequilibrio'));
        Schema::dropIfExists('rescisoes');
        Schema::dropIfExists('reequilibrios');
        Schema::dropIfExists('ata_consumos');
        Schema::dropIfExists('ata_adesoes');
        Schema::dropIfExists('ata_itens');
        Schema::dropIfExists('atas');
    }
};
