<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PNCP completo: documento anexado ao envio (edital, aviso, ata, contrato assinado), justificativa
 * de retificação e exclusão, resultado por item e número da ata no PNCP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envios_pncp', function (Blueprint $table) {
            $table->string('arquivo_caminho')->nullable()->after('payload');
            $table->string('documento_titulo', 255)->nullable()->after('arquivo_caminho');
            $table->unsignedSmallInteger('tipo_documento_id')->nullable()->after('documento_titulo');
            $table->text('justificativa')->nullable()->after('tipo_documento_id');
        });
        Schema::table('itens_processo', function (Blueprint $table) {
            $table->string('sequencial_resultado_pncp', 20)->nullable();
        });
        Schema::table('atas', function (Blueprint $table) {
            $table->string('numero_controle_pncp', 30)->nullable();
        });
        Schema::table('contratos', function (Blueprint $table) {
            $table->string('arquivo_caminho')->nullable();
            $table->string('arquivo_nome', 160)->nullable();
            $table->string('arquivo_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contratos', fn (Blueprint $t) => $t->dropColumn(['arquivo_caminho', 'arquivo_nome', 'arquivo_hash']));
        Schema::table('atas', fn (Blueprint $t) => $t->dropColumn('numero_controle_pncp'));
        Schema::table('itens_processo', fn (Blueprint $t) => $t->dropColumn('sequencial_resultado_pncp'));
        Schema::table('envios_pncp', fn (Blueprint $t) => $t->dropColumn(['arquivo_caminho', 'documento_titulo', 'tipo_documento_id', 'justificativa']));
    }
};
