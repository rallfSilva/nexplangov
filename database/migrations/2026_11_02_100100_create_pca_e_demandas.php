<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Plano de Contratações Anual (art. 12, VII, da Lei 14.133).
        Schema::create('planos_contratacao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->unsignedSmallInteger('ano');
            $table->string('situacao', 20)->default('rascunho'); // rascunho, em_aprovacao, aprovado
            $table->foreignId('aprovado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprovado_em')->nullable();
            $table->text('observacoes')->nullable();
            $table->string('numero_controle_pncp')->nullable();
            $table->timestamps();
            $table->unique(['orgao_id', 'ano']);
        });

        Schema::create('itens_pca', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('plano_contratacao_id')->constrained('planos_contratacao')->cascadeOnDelete();
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->nullOnDelete();
            $table->unsignedInteger('numero');
            $table->char('material_servico', 1); // M ou S
            $table->unsignedTinyInteger('categoria'); // CategoriaProcesso
            $table->string('codigo_catalogo', 20)->nullable(); // CATMAT / CATSER
            $table->string('descricao', 2000);
            $table->string('unidade_fornecimento', 30);
            $table->decimal('quantidade', 15, 4);
            $table->decimal('valor_unitario_estimado', 15, 4);
            $table->decimal('valor_total_estimado', 15, 2);
            $table->date('data_desejada')->nullable();
            $table->unsignedTinyInteger('prioridade')->default(2); // 1 alta, 2 média, 3 baixa
            $table->string('natureza_despesa', 40)->nullable(); // usado no controle de fracionamento (art. 75, § 1º)
            $table->timestamps();
            $table->unique(['plano_contratacao_id', 'numero']);
        });

        // Documento de Formalização da Demanda (art. 12, VII, e art. 72, I).
        Schema::create('demandas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->nullOnDelete();
            $table->foreignId('item_pca_id')->nullable()->constrained('itens_pca')->nullOnDelete();
            $table->foreignId('solicitante_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('numero', 30);
            $table->unsignedSmallInteger('ano');
            $table->string('objeto', 1000);
            $table->text('justificativa');
            $table->decimal('quantidade', 15, 4)->nullable();
            $table->decimal('valor_estimado', 15, 2)->nullable();
            $table->date('data_pretendida')->nullable();
            $table->unsignedTinyInteger('prioridade')->default(2);
            $table->string('situacao', 20)->default('enviada'); // rascunho, enviada, aceita, recusada, atendida
            $table->text('motivo_recusa')->nullable();
            $table->timestamps();
            $table->unique(['orgao_id', 'ano', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandas');
        Schema::dropIfExists('itens_pca');
        Schema::dropIfExists('planos_contratacao');
    }
};
