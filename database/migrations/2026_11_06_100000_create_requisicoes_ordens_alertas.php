<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisicoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('ata_item_id')->constrained('ata_itens')->restrictOnDelete();
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->nullOnDelete();
            $table->foreignId('requisitante_id')->constrained('users')->restrictOnDelete();
            $table->decimal('quantidade', 16, 4);
            $table->text('justificativa');
            $table->string('situacao', 12)->default('solicitada'); // solicitada, aprovada, recusada, atendida, cancelada
            $table->string('motivo_decisao', 500)->nullable();
            $table->foreignId('decidida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decidida_em')->nullable();
            $table->timestamps();
        });

        Schema::create('ordens_fornecimento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('ata_item_id')->constrained('ata_itens')->restrictOnDelete();
            $table->foreignId('requisicao_id')->nullable()->constrained('requisicoes')->nullOnDelete();
            $table->foreignId('consumo_id')->nullable()->constrained('ata_consumos')->nullOnDelete();
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->nullOnDelete();
            $table->foreignId('emitida_por')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('ano');
            $table->string('numero', 10);
            $table->decimal('quantidade', 16, 4);
            $table->decimal('valor_unitario', 16, 4);
            $table->decimal('valor_total', 16, 2);
            $table->date('prazo_entrega');
            $table->string('situacao', 10)->default('emitida'); // emitida, parcial, entregue, cancelada
            $table->string('motivo_cancelamento', 500)->nullable();
            $table->timestamps();
            $table->unique(['orgao_id', 'ano', 'numero']);
        });

        // Recebimento provisório e definitivo (art. 140).
        Schema::create('recebimentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('ordem_id')->constrained('ordens_fornecimento')->cascadeOnDelete();
            $table->string('tipo', 10); // provisorio, definitivo
            $table->decimal('quantidade', 16, 4);
            $table->string('documento', 60); // nota fiscal ou termo circunstanciado
            $table->date('data');
            $table->text('observacao')->nullable();
            $table->foreignId('recebido_por')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // Registro dos alertas já enviados, para não repetir o mesmo aviso.
        Schema::create('alertas_enviados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('chave', 120); // tipo:referencia:marco
            $table->timestamp('enviado_em')->useCurrent();
            $table->unique(['user_id', 'chave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas_enviados');
        Schema::dropIfExists('recebimentos');
        Schema::dropIfExists('ordens_fornecimento');
        Schema::dropIfExists('requisicoes');
    }
};
