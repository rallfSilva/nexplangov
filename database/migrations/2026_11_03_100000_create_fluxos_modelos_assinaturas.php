<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fluxo configurável por documento: o órgão define, para cada peça, dependências, prazo,
        // revisão, aprovação, assinatura, responsável e substituto. Pode ser salvo como modelo.
        Schema::create('fluxos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->string('nome', 120);
            $table->string('descricao', 500)->nullable();
            $table->string('aplicacao', 10)->default('todos'); // todos, licitacao, direta
            $table->unsignedTinyInteger('categoria')->nullable(); // CategoriaProcesso, quando específico
            $table->boolean('padrao')->default(false);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->unique(['orgao_id', 'nome']);
        });

        Schema::create('fluxo_etapas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('fluxo_id')->constrained('fluxos')->cascadeOnDelete();
            $this->colunasEtapa($table);
            $table->unique(['fluxo_id', 'tipo']);
        });

        Schema::table('processos', function (Blueprint $table) {
            $table->foreignId('fluxo_id')->nullable()->after('responsavel_id')->constrained('fluxos')->nullOnDelete();
        });

        // Cópia congelada do fluxo no processo: mudar o modelo depois não altera processos em andamento.
        Schema::create('etapas_processo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $this->colunasEtapa($table);
            $table->date('prazo_em')->nullable();
            $table->timestamp('solicitado_em')->nullable();
            $table->unique(['processo_id', 'tipo']);
        });

        // Biblioteca de modelos (minutas) editáveis por órgão, com campos dinâmicos e versão.
        Schema::create('modelos_documento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->string('codigo', 60); // identifica o modelo padrão da NexMind; null-safe para os criados pelo órgão
            $table->string('tipo', 40); // chave de App\Licitacao\TiposDocumento
            $table->string('titulo', 160);
            $table->string('descricao', 500)->nullable();
            $table->string('aplicacao', 10)->default('todos'); // todos, licitacao, direta
            $table->unsignedTinyInteger('categoria')->nullable(); // CategoriaProcesso, quando específico
            $table->jsonb('conteudo'); // campo da peça => texto com {{campos_dinamicos}}
            $table->unsignedInteger('versao')->default(1);
            $table->boolean('padrao_nexmind')->default(false);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->unique(['orgao_id', 'codigo']);
            $table->index(['orgao_id', 'tipo']);
        });

        Schema::create('modelos_documento_versoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('modelo_id')->constrained('modelos_documento')->cascadeOnDelete();
            $table->unsignedInteger('versao');
            $table->string('titulo', 160);
            $table->jsonb('conteudo');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('documentos_processo', function (Blueprint $table) {
            // situacao: rascunho, em_revisao, em_aprovacao, aguardando_assinatura, concluido
            $table->string('situacao', 30)->default('rascunho')->change();
            $table->string('origem', 12)->default('formulario'); // formulario, arquivo
            $table->foreignId('modelo_id')->nullable()->constrained('modelos_documento')->nullOnDelete();
            $table->unsignedInteger('modelo_versao')->nullable();
            $table->string('arquivo_caminho')->nullable();
            $table->string('arquivo_nome')->nullable();
            $table->unsignedBigInteger('arquivo_tamanho')->nullable();
            $table->char('arquivo_hash', 64)->nullable();
            $table->foreignId('revisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revisado_em')->nullable();
            $table->foreignId('aprovador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprovado_em')->nullable();
            $table->text('motivo_devolucao')->nullable();
        });

        // Assinatura eletrônica (individual ou coletiva), validável por QR Code.
        Schema::create('assinaturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('documento_id')->constrained('documentos_processo')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('nome', 255); // nome e cargo no momento da assinatura
            $table->string('perfil', 60);
            $table->timestamp('assinado_em');
            $table->char('hash_documento', 64); // hash do conteúdo que foi assinado
            $table->string('codigo', 20)->unique(); // código de validação, vai no QR Code
            $table->char('selo', 64); // HMAC do documento, do signatário e do instante
            $table->string('ip', 45)->nullable();
            $table->unique(['documento_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assinaturas');
        Schema::table('documentos_processo', function (Blueprint $t) {
            $t->dropConstrainedForeignId('modelo_id');
            $t->dropConstrainedForeignId('revisor_id');
            $t->dropConstrainedForeignId('aprovador_id');
            $t->dropColumn(['origem', 'modelo_versao', 'arquivo_caminho', 'arquivo_nome', 'arquivo_tamanho', 'arquivo_hash', 'revisado_em', 'aprovado_em', 'motivo_devolucao']);
        });
        Schema::dropIfExists('modelos_documento_versoes');
        Schema::dropIfExists('modelos_documento');
        Schema::dropIfExists('etapas_processo');
        Schema::table('processos', fn (Blueprint $t) => $t->dropConstrainedForeignId('fluxo_id'));
        Schema::dropIfExists('fluxo_etapas');
        Schema::dropIfExists('fluxos');
    }

    private function colunasEtapa(Blueprint $table): void
    {
        $table->string('tipo', 40); // chave de App\Licitacao\TiposDocumento
        $table->unsignedSmallInteger('ordem')->default(0);
        $table->jsonb('depende_de')->default('[]'); // tipos que precisam estar concluídos antes
        $table->unsignedSmallInteger('prazo_dias')->nullable();
        $table->boolean('exige_revisao')->default(false);
        $table->boolean('exige_aprovacao')->default(false);
        $table->boolean('exige_assinatura')->default(false);
        $table->string('origem', 12)->default('formulario'); // formulario, arquivo, solicitado
        $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('substituto_id')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('revisor_id')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('aprovador_id')->nullable()->constrained('users')->nullOnDelete();
        $table->jsonb('assinantes')->default('[]'); // ids de usuários; mais de um = assinatura coletiva
        $table->timestamps();
    }
};
