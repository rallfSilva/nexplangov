<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dotação autorizada na lei orçamentária, por natureza de despesa e exercício, para o cronograma do PCA
        // (art. 18, caput, e art. 150). Cadastro manual até haver integração com o sistema contábil.
        Schema::create('dotacoes_orcamentarias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->cascadeOnDelete();
            $table->unsignedSmallInteger('exercicio');
            $table->string('natureza_despesa', 40);
            $table->string('descricao', 200)->nullable(); // ficha, programa de trabalho, fonte
            $table->decimal('valor_autorizado', 16, 2);
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['orgao_id', 'exercicio', 'natureza_despesa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dotacoes_orcamentarias');
    }
};
