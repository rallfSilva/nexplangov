<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encerramento da sessão pública com ata e gravação (art. 17, §§ 2º e 5º) e fase recursal
 * (arts. 165 a 168): intenção imediata, razões, contrarrazões, reconsideração e decisão superior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processos', function (Blueprint $table) {
            $table->timestamp('sessao_encerrada_em')->nullable();
            $table->foreignId('sessao_encerrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ata_sessao_caminho')->nullable();
            $table->string('ata_sessao_hash', 64)->nullable();
            $table->string('sessao_reaberta_motivo', 500)->nullable();
            $table->string('gravacao_link', 500)->nullable();
            $table->string('gravacao_caminho')->nullable();
            $table->string('gravacao_nome', 160)->nullable();
            $table->string('gravacao_hash', 64)->nullable();
        });

        Schema::create('recursos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('itens_processo')->nullOnDelete();
            $table->foreignId('fornecedor_id')->constrained('fornecedores')->restrictOnDelete();
            $table->string('tipo', 20); // julgamento, habilitacao, revogacao_anulacao (art. 165, I)
            $table->string('situacao', 20)->default('intencao'); // intencao, razoes, superior, reconsiderado, decidido, sem_razoes
            $table->text('intencao_motivo');
            $table->timestamp('intencao_em');
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->date('prazo_razoes')->nullable();
            $table->text('razoes')->nullable();
            $table->timestamp('razoes_em')->nullable();
            $table->date('prazo_contrarrazoes')->nullable();
            $table->date('prazo_reconsideracao')->nullable();
            $table->string('reconsideracao', 12)->nullable(); // reconsiderou, manteve
            $table->text('reconsideracao_texto')->nullable();
            $table->foreignId('reconsiderado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconsiderado_em')->nullable();
            $table->date('prazo_decisao')->nullable();
            $table->string('resultado', 12)->nullable(); // provido, parcial, improvido
            $table->text('decisao')->nullable();
            $table->foreignId('decidido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decidido_em')->nullable();
            $table->timestamps();
            $table->index(['processo_id', 'situacao']);
        });

        Schema::create('recurso_contrarrazoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('recurso_id')->constrained('recursos')->cascadeOnDelete();
            $table->foreignId('fornecedor_id')->constrained('fornecedores')->restrictOnDelete();
            $table->text('texto');
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['recurso_id', 'fornecedor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurso_contrarrazoes');
        Schema::dropIfExists('recursos');
        Schema::table('processos', fn (Blueprint $t) => $t->dropConstrainedForeignId('sessao_encerrada_por'));
        Schema::table('processos', fn (Blueprint $t) => $t->dropColumn(['sessao_encerrada_em', 'ata_sessao_caminho', 'ata_sessao_hash', 'sessao_reaberta_motivo', 'gravacao_link', 'gravacao_caminho', 'gravacao_nome', 'gravacao_hash']));
    }
};
