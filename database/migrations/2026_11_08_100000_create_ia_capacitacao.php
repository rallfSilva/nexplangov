<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registro de uso da IA: sem o texto, só o hash do pedido e o consumo.
        Schema::create('usos_ia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('processo_id')->nullable()->constrained('processos')->nullOnDelete();
            $table->string('tipo_documento', 40);
            $table->string('acao', 12); // sugerir | revisar
            $table->char('hash_pedido', 64);
            $table->unsignedInteger('tokens_entrada')->default(0);
            $table->unsignedInteger('tokens_saida')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });

        // Capacitação dos agentes públicos (art. 7º, II, e art. 173).
        Schema::create('capacitacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('curso', 200);
            $table->string('instituicao', 200);
            $table->boolean('escola_governo')->default(false);
            $table->string('tema', 30); // planejamento, licitacao, contratos, pregao, governanca, outro
            $table->unsignedSmallInteger('carga_horaria');
            $table->date('concluida_em');
            $table->string('certificado', 120)->nullable(); // número ou código de validação
            $table->foreignId('registrada_por')->constrained('users');
            $table->timestamps();
            $table->index(['orgao_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capacitacoes');
        Schema::dropIfExists('usos_ia');
    }
};
