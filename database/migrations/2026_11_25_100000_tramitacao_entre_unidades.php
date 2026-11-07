<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tramitação do processo entre unidades (remessa, recebimento, cancelamento da remessa, arquivamento e
 * desarquivamento), lotação dos usuários e unidade onde o processo está. A situação do processo (fase da Lei
 * 14.133) continua só na TramitacaoProcesso; aqui se registra onde os autos estão e com quem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('unidade_id')->nullable()->after('orgao_id')->constrained('unidades')->nullOnDelete();
        });

        Schema::table('processos', function (Blueprint $table) {
            $table->foreignId('unidade_atual_id')->nullable()->after('unidade_id')->constrained('unidades')->nullOnDelete();
            $table->timestamp('arquivado_em')->nullable();
            $table->foreignId('arquivado_por')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement('UPDATE processos SET unidade_atual_id = unidade_id');

        Schema::create('tramites_processo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $table->string('tipo', 20); // remessa, arquivamento, desarquivamento
            $table->foreignId('unidade_origem_id')->nullable()->constrained('unidades')->nullOnDelete();
            $table->foreignId('unidade_destino_id')->nullable()->constrained('unidades')->nullOnDelete();
            $table->text('despacho')->nullable();
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enviado_em');
            $table->foreignId('recebido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recebido_em')->nullable();
            $table->foreignId('cancelado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelado_em')->nullable();
            $table->text('motivo_cancelamento')->nullable();
            $table->timestamps();
            $table->index(['processo_id', 'enviado_em']);
            $table->index(['unidade_destino_id', 'recebido_em', 'cancelado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tramites_processo');
        Schema::table('processos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unidade_atual_id');
            $table->dropConstrainedForeignId('arquivado_por');
            $table->dropColumn('arquivado_em');
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('unidade_id'));
    }
};
