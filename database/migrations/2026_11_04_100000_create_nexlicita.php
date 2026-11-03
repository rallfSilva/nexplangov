<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Impugnações e pedidos de esclarecimento (art. 164).
        Schema::create('impugnacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $table->string('protocolo', 20)->unique();
            $table->string('tipo', 14); // impugnacao, esclarecimento
            $table->string('canal', 10)->default('interno'); // publico, interno
            $table->string('autor_nome', 160);
            $table->text('autor_email')->nullable(); // cifrado (LGPD)
            $table->text('texto');
            $table->timestamp('recebida_em');
            $table->date('limite_protocolo')->nullable();
            $table->boolean('tempestiva')->default(true);
            $table->date('prazo_resposta_em')->nullable();
            $table->string('situacao', 14)->default('recebida'); // recebida, respondida, nao_conhecida
            $table->string('resultado', 12)->nullable(); // procedente, parcial, improcedente
            $table->text('resposta')->nullable();
            $table->foreignId('respondida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('respondida_em')->nullable();
            $table->timestamps();
            $table->index(['processo_id', 'situacao']);
        });

        // Propostas por item e fornecedor, julgamento e habilitação (arts. 33, 59 a 71).
        Schema::create('propostas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('itens_processo')->cascadeOnDelete();
            $table->foreignId('fornecedor_id')->constrained('fornecedores')->restrictOnDelete();
            $table->decimal('valor', 16, 4); // valor unitário; no maior desconto, o percentual
            $table->string('marca_modelo', 160)->nullable();
            $table->string('situacao', 16)->default('classificada'); // classificada, desclassificada, vencedora, recusada
            $table->string('motivo', 500)->nullable();
            $table->boolean('me_epp')->default(false); // porte no momento da proposta
            $table->string('habilitacao', 12)->default('pendente'); // pendente, habilitado, inabilitado
            $table->string('habilitacao_motivo', 500)->nullable();
            $table->string('desempate_criterio', 24)->nullable();
            $table->string('desempate_justificativa', 500)->nullable();
            $table->foreignId('registrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['item_id', 'fornecedor_id']);
        });

        Schema::create('lances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('itens_processo')->cascadeOnDelete();
            $table->foreignId('proposta_id')->constrained('propostas')->cascadeOnDelete();
            $table->decimal('valor', 16, 4);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('itens_processo', function (Blueprint $table) {
            $table->foreignId('proposta_id')->nullable()->constrained('propostas')->nullOnDelete();
            $table->timestamp('adjudicado_em')->nullable(); // art. 71, IV
            $table->foreignId('adjudicado_por')->nullable()->constrained('users')->nullOnDelete();
        });

        // Intenção de registro de preços (art. 86).
        Schema::create('irps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->nullOnDelete();
            $table->foreignId('processo_id')->nullable()->constrained('processos')->nullOnDelete();
            $table->foreignId('criada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('ano');
            $table->string('numero', 10);
            $table->string('objeto', 500);
            $table->text('justificativa')->nullable();
            $table->date('manifestacao_ate');
            $table->string('situacao', 12)->default('aberta'); // aberta, encerrada, consolidada, cancelada
            $table->timestamps();
            $table->unique(['orgao_id', 'ano', 'numero']);
        });

        Schema::create('irp_manifestacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('irp_id')->constrained('irps')->cascadeOnDelete();
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->nullOnDelete();
            $table->string('participante', 160); // unidade do órgão ou órgão externo
            $table->string('descricao', 300);
            $table->string('unidade_medida', 20);
            $table->decimal('quantidade', 16, 4);
            $table->text('justificativa')->nullable();
            $table->string('situacao', 10)->default('enviada'); // enviada, aceita, recusada
            $table->string('motivo_recusa', 500)->nullable();
            $table->foreignId('enviada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('irp_manifestacoes');
        Schema::dropIfExists('irps');
        Schema::table('itens_processo', function (Blueprint $t) {
            $t->dropConstrainedForeignId('adjudicado_por');
            $t->dropConstrainedForeignId('proposta_id');
            $t->dropColumn('adjudicado_em');
        });
        Schema::dropIfExists('lances');
        Schema::dropIfExists('propostas');
        Schema::dropIfExists('impugnacoes');
    }
};
