<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo nacional (CATMAT/CATSER): compartilhado por todos os órgãos, por isso sem orgao_id.
        Schema::create('catalogo_itens', function (Blueprint $table) {
            $table->id();
            $table->char('tipo', 1); // M = material (CATMAT), S = serviço (CATSER)
            $table->string('codigo', 20);
            $table->string('descricao', 1000);
            $table->string('classe_codigo', 20)->nullable();
            $table->string('classe_nome', 200)->nullable();
            $table->string('unidade_padrao', 40)->nullable();
            $table->boolean('ativo')->default(true);
            $table->string('fonte', 20)->default('importado'); // importado | demonstracao
            $table->timestamps();
            $table->unique(['tipo', 'codigo']);
            $table->index('descricao');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogo_itens');
    }
};
