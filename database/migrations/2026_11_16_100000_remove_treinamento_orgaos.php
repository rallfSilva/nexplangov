<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retira as turmas de treinamento. Em bancos que já tinham as colunas, as turmas existentes são
 * desativadas (nada é apagado, para a trilha de auditoria continuar íntegra) e as colunas saem.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orgaos', 'treinamento')) {
            return;
        }

        $turmas = DB::table('orgaos')->where('treinamento', true)->pluck('id');
        if ($turmas->isNotEmpty()) {
            DB::table('orgaos')->whereIn('id', $turmas)->update(['ativo' => false]);
            DB::table('users')->whereIn('orgao_id', $turmas)->update(['ativo' => false]);
        }

        Schema::table('orgaos', function (Blueprint $table) {
            $table->dropIndex(['treinamento']);
            $table->dropColumn(['treinamento', 'treinamento_expira_em']);
        });
    }

    public function down(): void
    {
        // Sem volta: o módulo de treinamento foi retirado do sistema.
    }
};
