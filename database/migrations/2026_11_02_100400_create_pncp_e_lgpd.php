<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fila e histórico de todo envio ao PNCP. Nada é marcado como publicado sem resposta 2xx.
        Schema::create('envios_pncp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->morphs('publicavel');
            $table->string('recurso', 20); // compra, contrato, termo, pca
            $table->string('operacao', 12); // inserir, retificar, excluir
            $table->string('estado', 12)->default('pendente'); // pendente, enviando, publicado, erro
            $table->string('ambiente', 12);
            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->jsonb('payload');
            $table->string('metodo', 8)->nullable();
            $table->string('rota')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->jsonb('resposta')->nullable();
            $table->string('location')->nullable();
            $table->text('erro')->nullable();
            $table->timestamp('publicado_em')->nullable();
            $table->timestamps();
            $table->index(['orgao_id', 'estado']);
        });

        // LGPD, art. 37: registro das operações de tratamento de dados pessoais.
        Schema::create('operacoes_tratamento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->string('nome');
            $table->text('finalidade');
            $table->string('base_legal', 40); // art. 7º ou art. 11 da LGPD
            $table->text('dados_tratados');
            $table->string('titulares');
            $table->text('compartilhamento')->nullable();
            $table->string('retencao');
            $table->text('medidas_seguranca');
            $table->timestamps();
        });

        // LGPD, art. 18: pedidos dos titulares, com prazo de 15 dias (art. 19, II).
        Schema::create('solicitacoes_titular', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->string('protocolo', 20)->unique();
            $table->string('nome');
            $table->string('email');
            $table->text('cpf_cifrado')->nullable();
            $table->string('tipo', 30);
            $table->text('descricao');
            $table->string('situacao', 20)->default('aberta'); // aberta, em_atendimento, concluida, indeferida
            $table->date('prazo_em');
            $table->text('resposta')->nullable();
            $table->foreignId('atendido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('concluida_em')->nullable();
            $table->timestamps();
            $table->index(['orgao_id', 'situacao']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitacoes_titular');
        Schema::dropIfExists('operacoes_tratamento');
        Schema::dropIfExists('envios_pncp');
    }
};
