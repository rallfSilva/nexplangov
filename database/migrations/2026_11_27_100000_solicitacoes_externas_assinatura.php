<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos por e-mail a pessoas de fora do órgão (fornecedor, outro órgão, cidadão): notificação com ciência,
 * assinatura de peça e envio de documentos. O link leva um código aleatório; o banco guarda só o hash dele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitacoes_externas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('processo_id')->constrained('processos')->restrictOnDelete();
            $table->foreignId('documento_id')->nullable()->constrained('documentos_processo')->nullOnDelete();
            $table->string('tipo', 20); // notificacao, assinatura, documentos
            $table->string('nome', 160);
            $table->string('email', 255);
            $table->text('mensagem');
            $table->char('token_hash', 64)->unique();
            $table->date('prazo_em');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enviado_em')->nullable();
            $table->timestamp('respondida_em')->nullable();
            $table->text('resposta')->nullable();
            $table->string('ip_resposta', 45)->nullable();
            $table->timestamp('cancelada_em')->nullable();
            $table->foreignId('cancelada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['processo_id', 'created_at']);
        });

        Schema::table('assinaturas', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('solicitacao_externa_id')->nullable()->after('user_id')->constrained('solicitacoes_externas')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assinaturas', fn (Blueprint $table) => $table->dropConstrainedForeignId('solicitacao_externa_id'));
        Schema::dropIfExists('solicitacoes_externas');
    }
};
