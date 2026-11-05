<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consultas automáticas ao CEIS e ao CNEP (art. 161) pela API do Portal da Transparência. Cada consulta guarda
 * o resultado resumido e o hash da resposta, para provar o que constava no cadastro naquele momento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultas_impedimento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('fornecedor_id')->constrained('fornecedores')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('consultado_em');
            $table->string('situacao', 15); // regular, sancionado, indisponivel
            $table->jsonb('registros')->nullable(); // sanções encontradas, resumidas
            $table->char('assinatura_registros', 64)->nullable(); // identifica o mesmo conjunto de sanções entre consultas
            $table->char('hash_resposta', 64)->nullable();
            $table->text('erro')->nullable();
            $table->text('ressalva_justificativa')->nullable(); // análise de abrangência (art. 156, § 4º)
            $table->foreignId('ressalva_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ressalva_em')->nullable();
            $table->timestamps();
            $table->index(['fornecedor_id', 'consultado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultas_impedimento');
    }
};
