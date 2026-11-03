<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Órgão de treinamento: turma com dados fictícios, isolada como qualquer órgão, que nunca publica
 * no PNCP, não envia e-mail de alerta e tem prazo para acabar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orgaos', function (Blueprint $table) {
            $table->boolean('treinamento')->default(false)->after('ativo');
            $table->date('treinamento_expira_em')->nullable()->after('treinamento');
            $table->index('treinamento');
        });
    }

    public function down(): void
    {
        Schema::table('orgaos', function (Blueprint $table) {
            $table->dropIndex(['treinamento']);
            $table->dropColumn(['treinamento', 'treinamento_expira_em']);
        });
    }
};
