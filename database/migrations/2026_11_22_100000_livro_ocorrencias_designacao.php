<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Livro de ocorrências do contrato (art. 117, §§ 1º e 2º) e designação formal de gestor e fiscal (art. 117,
 * caput). O livro é só de inclusão, encadeado por hash; correção se faz por nova anotação que retifica a anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocorrencias_contrato', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('contrato_id')->constrained('contratos')->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('autor_nome'); // fica mesmo que o usuário seja removido
            $table->string('autor_papel', 20); // fiscal, gestor
            $table->string('tipo', 30);
            $table->date('ocorrida_em');
            $table->text('descricao');
            $table->text('providencias')->nullable();
            $table->date('prazo_regularizacao')->nullable();
            $table->foreignId('retifica_id')->nullable()->constrained('ocorrencias_contrato')->restrictOnDelete();
            $table->char('hash_anterior', 64)->nullable();
            $table->char('hash', 64)->unique();
            $table->timestamp('registrada_em');
            $table->unique(['contrato_id', 'numero']);
        });

        Schema::table('contratos', function (Blueprint $table) {
            $table->string('designacao_ato', 60)->nullable(); // ex.: Portaria nº 12/2026
            $table->date('designacao_em')->nullable();
            $table->foreignId('designado_por')->nullable()->constrained('users')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION nexplangov_bloquear_alteracao_ocorrencia() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'O livro de ocorrências não pode ser alterado nem apagado. Registre uma retificação.';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER ocorrencias_contrato_imutavel
                    BEFORE UPDATE OR DELETE ON ocorrencias_contrato
                    FOR EACH ROW EXECUTE FUNCTION nexplangov_bloquear_alteracao_ocorrencia();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS ocorrencias_contrato_imutavel ON ocorrencias_contrato;');
            DB::unprepared('DROP FUNCTION IF EXISTS nexplangov_bloquear_alteracao_ocorrencia();');
        }

        Schema::table('contratos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('designado_por');
            $table->dropColumn(['designacao_ato', 'designacao_em']);
        });

        Schema::dropIfExists('ocorrencias_contrato');
    }
};
