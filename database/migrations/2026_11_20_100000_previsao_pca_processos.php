<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Previsão da contratação no PCA (art. 12, VII, e art. 18, § 1º, II): o processo aponta o item do plano
 * que atende ou traz a justificativa de estar fora dele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processos', function (Blueprint $table) {
            $table->foreignId('item_pca_id')->nullable()->after('demanda_id')->constrained('itens_pca')->nullOnDelete();
            $table->text('justificativa_fora_pca')->nullable()->after('item_pca_id');
        });
    }

    public function down(): void
    {
        Schema::table('processos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_pca_id');
            $table->dropColumn('justificativa_fora_pca');
        });
    }
};
