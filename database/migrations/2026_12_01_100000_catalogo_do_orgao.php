<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo do órgão (art. 19, II, e art. 40, § 1º, I): descrição padronizada, atributos e imagem, ligada ao
        // código nacional CATMAT/CATSER. A descrição normalizada impede o mesmo item cadastrado duas vezes.
        Schema::create('itens_catalogo_orgao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->cascadeOnDelete();
            $table->char('tipo', 1); // M = material, S = serviço
            $table->string('codigo_catalogo', 20)->nullable();
            $table->string('descricao', 1000);
            $table->string('descricao_normalizada', 1000);
            $table->string('unidade_fornecimento', 40);
            $table->jsonb('atributos')->default('[]');
            $table->text('especificacao')->nullable();
            $table->string('natureza_despesa', 20)->nullable();
            $table->string('imagem_caminho')->nullable();
            $table->string('imagem_hash', 64)->nullable();
            $table->boolean('ativo')->default(true);
            $table->string('motivo_inativacao', 500)->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['orgao_id', 'tipo', 'descricao_normalizada']);
            $table->index(['orgao_id', 'codigo_catalogo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itens_catalogo_orgao');
    }
};
