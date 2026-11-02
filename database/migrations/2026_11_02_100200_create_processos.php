<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->nullOnDelete();
            $table->foreignId('demanda_id')->nullable()->constrained('demandas')->nullOnDelete();
            $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('numero', 30);
            $table->unsignedSmallInteger('ano');
            $table->string('numero_compra', 30)->nullable(); // número do edital ou do aviso
            $table->string('objeto', 5120); // limite do campo objetoCompra no PNCP
            $table->unsignedTinyInteger('modalidade');
            $table->unsignedTinyInteger('amparo_legal');
            $table->unsignedTinyInteger('criterio_julgamento')->default(1);
            $table->unsignedTinyInteger('modo_disputa')->default(1);
            $table->unsignedTinyInteger('categoria');
            $table->boolean('srp')->default(false); // sistema de registro de preços (art. 82)
            $table->boolean('orcamento_sigiloso')->default(false); // art. 24
            $table->boolean('com_disputa')->default(false); // dispensa com disputa eletrônica (art. 75, § 3º)
            $table->string('natureza_despesa', 40)->nullable();
            $table->decimal('valor_estimado', 15, 2)->default(0);
            $table->decimal('valor_homologado', 15, 2)->nullable();
            $table->timestamp('abertura_propostas_em')->nullable();
            $table->timestamp('encerramento_propostas_em')->nullable();
            $table->string('situacao', 30)->default('planejamento');
            $table->string('numero_controle_pncp')->nullable();
            $table->timestamps();
            $table->unique(['orgao_id', 'ano', 'numero']);
            $table->index(['orgao_id', 'situacao']);
        });

        // Peças da instrução (DFD, ETP, mapa de riscos, TR, parecer, autorização...). Cada versão concluída
        // fica com hash do conteúdo para provar que não mudou depois de assinada.
        Schema::create('documentos_processo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $table->string('tipo', 40);
            $table->unsignedInteger('versao')->default(1);
            $table->jsonb('conteudo');
            $table->string('situacao', 20)->default('rascunho'); // rascunho, concluido
            $table->foreignId('autor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('concluido_em')->nullable();
            $table->char('hash_conteudo', 64)->nullable();
            $table->timestamps();
            $table->index(['processo_id', 'tipo']);
        });

        Schema::create('itens_processo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $table->foreignId('item_pca_id')->nullable()->constrained('itens_pca')->nullOnDelete();
            $table->unsignedInteger('numero');
            $table->char('material_servico', 1);
            $table->string('codigo_catalogo', 20)->nullable();
            $table->string('descricao', 2000);
            $table->string('unidade_medida', 30);
            $table->decimal('quantidade', 15, 4);
            $table->string('metodo_preco', 10)->default('mediana'); // mediana, media, menor
            $table->decimal('valor_unitario_estimado', 15, 4)->default(0);
            $table->decimal('valor_total_estimado', 15, 2)->default(0);
            $table->unsignedTinyInteger('tipo_beneficio')->default(4); // tabela 5.7 do PNCP; 4 = sem benefício
            $table->string('situacao', 15)->default('pendente'); // pendente, homologado, cancelado, deserto
            $table->foreignId('fornecedor_vencedor_id')->nullable()->constrained('fornecedores')->nullOnDelete();
            $table->decimal('valor_unitario_homologado', 15, 4)->nullable();
            $table->timestamps();
            $table->unique(['processo_id', 'numero']);
        });

        // Pesquisa de preços (art. 23).
        Schema::create('cotacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('item_processo_id')->constrained('itens_processo')->cascadeOnDelete();
            $table->string('fonte', 30);
            $table->string('referencia'); // nome do fornecedor, órgão da contratação similar, sítio...
            $table->string('documento_fonte', 18)->nullable(); // CNPJ do fornecedor, quando houver
            $table->decimal('valor_unitario', 15, 4);
            $table->date('data_cotacao');
            $table->boolean('desconsiderada')->default(false);
            $table->string('motivo_desconsideracao')->nullable();
            $table->timestamps();
        });

        Schema::create('eventos_processo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('de', 30)->nullable();
            $table->string('para', 30);
            $table->text('justificativa')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_processo');
        Schema::dropIfExists('cotacoes');
        Schema::dropIfExists('itens_processo');
        Schema::dropIfExists('documentos_processo');
        Schema::dropIfExists('processos');
    }
};
