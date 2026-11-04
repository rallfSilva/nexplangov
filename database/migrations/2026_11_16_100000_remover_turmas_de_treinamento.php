<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove as turmas de treinamento. A capacitação dos usuários passa a ser um serviço da implantação
 * (previsto nos termos de referência), não uma função do sistema. Os órgãos de turma que existirem
 * ficam inativos (nada é apagado, a trilha de auditoria continua íntegra) e seus usuários perdem o acesso.
 */
return new class extends Migration
{
    public function up(): void
    {
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
        Schema::table('orgaos', function (Blueprint $table) {
            $table->boolean('treinamento')->default(false)->after('ativo');
            $table->date('treinamento_expira_em')->nullable()->after('treinamento');
            $table->index('treinamento');
        });
    }
};
