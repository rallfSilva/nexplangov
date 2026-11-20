<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transparência da pesquisa de preços (art. 23, § 1º): cada preço trazido de portal guarda a identificação da compra
 * de origem e o momento da consulta, além do link já existente, para o servidor e o controle conferirem na fonte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotacoes', function (Blueprint $table) {
            $table->string('identificacao_fonte', 160)->nullable(); // ex.: "Pregão 90012/2026 · item 3" ou nº de controle do PNCP
            $table->timestamp('consultado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cotacoes', fn (Blueprint $t) => $t->dropColumn(['identificacao_fonte', 'consultado_em']));
    }
};
