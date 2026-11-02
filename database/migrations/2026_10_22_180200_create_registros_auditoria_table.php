<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registros_auditoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('evento', 40)->index();
            $table->nullableMorphs('auditable');
            $table->string('descricao');
            $table->jsonb('valores_antigos')->nullable();
            $table->jsonb('valores_novos')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            // Encadeamento: cada registro guarda o hash do anterior. Qualquer edição direta no banco quebra a cadeia.
            $table->char('hash_anterior', 64)->nullable();
            $table->char('hash', 64)->nullable()->unique();
        });

        // Sem FKs de propósito: o registro precisa sobreviver mesmo que o usuário ou o órgão
        // seja removido. A trilha é somente inclusão, garantida também no banco.
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION nexplangov_bloquear_alteracao_auditoria() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'A trilha de auditoria não pode ser alterada nem apagada.';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER registros_auditoria_imutavel
                    BEFORE UPDATE OR DELETE ON registros_auditoria
                    FOR EACH ROW EXECUTE FUNCTION nexplangov_bloquear_alteracao_auditoria();

                CREATE TRIGGER registros_auditoria_sem_truncate
                    BEFORE TRUNCATE ON registros_auditoria
                    FOR EACH STATEMENT EXECUTE FUNCTION nexplangov_bloquear_alteracao_auditoria();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS registros_auditoria_imutavel ON registros_auditoria;');
            DB::unprepared('DROP TRIGGER IF EXISTS registros_auditoria_sem_truncate ON registros_auditoria;');
            DB::unprepared('DROP FUNCTION IF EXISTS nexplangov_bloquear_alteracao_auditoria();');
        }

        Schema::dropIfExists('registros_auditoria');
    }
};
