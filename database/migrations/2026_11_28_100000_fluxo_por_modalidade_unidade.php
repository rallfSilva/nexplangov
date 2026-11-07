<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fluxo por tipo de processo (modalidade) e por secretaria (unidade que abre o processo), com roteiro de unidades
 * que sugere o destino de cada remessa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fluxos', function (Blueprint $table) {
            $table->unsignedTinyInteger('modalidade')->nullable()->after('categoria'); // Modalidade (código do PNCP)
            $table->foreignId('unidade_id')->nullable()->after('modalidade')->constrained('unidades')->nullOnDelete();
            $table->jsonb('roteiro_unidades')->nullable()->after('unidade_id');
        });
    }

    public function down(): void
    {
        Schema::table('fluxos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unidade_id');
            $table->dropColumn(['modalidade', 'roteiro_unidades']);
        });
    }
};
