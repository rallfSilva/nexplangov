<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pacote de devolução dos dados do órgão (fim do contrato ou pedido do órgão): todos os registros
 * em CSV e JSON, os arquivos enviados e um manifesto com o SHA-256 de cada parte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pacotes_devolucao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('situacao', 20)->default('na_fila'); // na_fila, gerando, pronto, erro, expirado
            $table->string('arquivo_caminho')->nullable();
            $table->unsignedBigInteger('tamanho')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->json('resumo')->nullable(); // registros por tabela e quantidade de arquivos
            $table->text('erro')->nullable();
            $table->timestamp('expira_em')->nullable();
            $table->timestamp('baixado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pacotes_devolucao');
    }
};
