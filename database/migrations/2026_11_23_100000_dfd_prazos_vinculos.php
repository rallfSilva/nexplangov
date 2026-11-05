<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DFD: prazo para as unidades enviarem as demandas que vão compor o PCA de cada exercício (art. 12, VII), com as
 * exceções que não esperam o calendário, e indicação de demandas vinculadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prazos_dfd', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->unsignedSmallInteger('exercicio'); // ano do PCA que as demandas vão compor
            $table->date('data_limite');
            $table->foreignId('definido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['orgao_id', 'exercicio']);
        });

        Schema::table('demandas', function (Blueprint $table) {
            $table->unsignedSmallInteger('exercicio')->nullable()->after('ano');
            $table->string('excecao_prazo', 30)->nullable()->after('situacao');
            $table->text('justificativa_excecao')->nullable()->after('excecao_prazo');
        });

        Schema::create('demanda_vinculos', function (Blueprint $table) {
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('demanda_id')->constrained('demandas')->cascadeOnDelete();
            $table->foreignId('vinculada_id')->constrained('demandas')->cascadeOnDelete();
            $table->primary(['demanda_id', 'vinculada_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demanda_vinculos');
        Schema::table('demandas', fn (Blueprint $table) => $table->dropColumn(['exercicio', 'excecao_prazo', 'justificativa_excecao']));
        Schema::dropIfExists('prazos_dfd');
    }
};
