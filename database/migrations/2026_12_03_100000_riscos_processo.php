<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Matriz de riscos estruturada do processo (art. 6º, XXVII, art. 18, X, e art. 22).
        Schema::create('riscos_processo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->cascadeOnDelete();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordem');
            $table->string('risco', 500);
            $table->string('causa', 1000)->nullable();
            $table->string('consequencia', 1000)->nullable();
            $table->unsignedTinyInteger('probabilidade');
            $table->unsignedTinyInteger('impacto');
            $table->string('alocacao', 15); // contratante | contratado | compartilhado (art. 103)
            $table->text('tratamento')->nullable();
            $table->string('responsavel', 200)->nullable();
            $table->string('origem', 10)->default('manual'); // manual | ia (aceita pelo servidor)
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['processo_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('riscos_processo');
    }
};
