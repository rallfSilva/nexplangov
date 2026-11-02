<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contratos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('processo_id')->nullable()->constrained('processos')->nullOnDelete();
            $table->foreignId('fornecedor_id')->constrained('fornecedores')->restrictOnDelete();
            $table->foreignId('gestor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('fiscal_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('numero', 30);
            $table->unsignedSmallInteger('ano');
            $table->unsignedTinyInteger('tipo')->default(1); // tabela 5.9 do PNCP; 1 = contrato
            $table->string('objeto', 5120);
            $table->unsignedTinyInteger('categoria');
            $table->boolean('servico_continuo')->default(false); // arts. 106 e 107
            $table->boolean('reforma_equipamento')->default(false); // art. 125: limite de 50% nos acréscimos
            $table->decimal('valor_inicial', 15, 2);
            $table->decimal('valor_global', 15, 2);
            $table->decimal('garantia_percentual', 5, 2)->nullable(); // arts. 96 a 98
            $table->text('justificativa_garantia')->nullable();
            $table->date('assinatura');
            $table->date('vigencia_inicio');
            $table->date('vigencia_fim');
            $table->string('fundamento_vigencia_especial')->nullable(); // arts. 108 a 110
            $table->string('situacao', 20)->default('vigente'); // vigente, encerrado, rescindido
            $table->string('numero_controle_pncp')->nullable();
            $table->timestamps();
            $table->unique(['orgao_id', 'ano', 'numero']);
            $table->index(['orgao_id', 'vigencia_fim']);
        });

        Schema::create('termos_contrato', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('contrato_id')->constrained('contratos')->cascadeOnDelete();
            $table->string('tipo', 20);
            $table->string('numero', 30);
            $table->date('assinatura');
            $table->decimal('valor_acrescimo', 15, 2)->default(0);
            $table->decimal('valor_supressao', 15, 2)->default(0);
            $table->decimal('valor_reajuste', 15, 2)->default(0);
            $table->date('nova_vigencia_fim')->nullable();
            $table->text('justificativa');
            $table->string('numero_controle_pncp')->nullable();
            $table->timestamps();
            $table->unique(['contrato_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('termos_contrato');
        Schema::dropIfExists('contratos');
    }
};
