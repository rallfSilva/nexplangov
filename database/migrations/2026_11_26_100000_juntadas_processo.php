<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Juntada livre de documentos aos autos (certidões, ofícios, comprovantes, documentos enviados por externos).
 * O documento juntado não se altera nem se apaga: se foi juntado por engano, é desentranhado com motivo e continua
 * no histórico, como no processo em papel. O gatilho do banco garante isso também fora da aplicação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('juntadas_processo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('processo_id')->constrained('processos')->restrictOnDelete();
            $table->string('tipo', 30);
            $table->string('titulo', 200);
            $table->text('descricao')->nullable();
            $table->string('arquivo_caminho');
            $table->string('arquivo_nome', 160);
            $table->unsignedBigInteger('arquivo_tamanho');
            $table->char('arquivo_hash', 64);
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('paginas')->nullable();
            $table->string('origem', 20)->default('interno'); // interno ou externo
            $table->foreignId('autor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('autor_externo', 160)->nullable();
            $table->unsignedBigInteger('solicitacao_externa_id')->nullable();
            $table->timestamp('juntado_em');
            $table->timestamp('desentranhado_em')->nullable();
            $table->foreignId('desentranhado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motivo_desentranhamento')->nullable();
            $table->timestamps();
            $table->index(['processo_id', 'juntado_em']);
        });

        // Só o desentranhamento muda a juntada; nada se apaga.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION juntadas_processo_imutavel() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Documento juntado aos autos não pode ser apagado; use o desentranhamento.';
                END IF;
                IF OLD.desentranhado_em IS NOT NULL
                   OR NEW.processo_id <> OLD.processo_id OR NEW.tipo <> OLD.tipo OR NEW.titulo <> OLD.titulo
                   OR NEW.arquivo_caminho <> OLD.arquivo_caminho OR NEW.arquivo_hash <> OLD.arquivo_hash OR NEW.juntado_em <> OLD.juntado_em THEN
                    RAISE EXCEPTION 'Documento juntado aos autos não pode ser alterado.';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER juntadas_processo_imutavel BEFORE UPDATE OR DELETE ON juntadas_processo
                FOR EACH ROW EXECUTE FUNCTION juntadas_processo_imutavel();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS juntadas_processo_imutavel ON juntadas_processo; DROP FUNCTION IF EXISTS juntadas_processo_imutavel();');
        Schema::dropIfExists('juntadas_processo');
    }
};
