<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro dos envios ao Painel de Compras (SIC/SELC): um por lote enviado, com o lote devolvido pelo
 * painel ou o erro. Serve para conferir a integração; os dados em si ficam nas tabelas de origem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envios_painel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->nullable()->constrained('orgaos')->nullOnDelete();
            $table->string('origem_type', 120)->nullable(); // modelo que provocou o envio (nulo na carga inicial)
            $table->unsignedBigInteger('origem_id')->nullable();
            $table->string('tipo', 30);
            $table->unsignedInteger('registros');
            $table->string('chave', 100)->unique(); // Idempotency-Key enviada ao painel
            $table->string('estado', 12); // enviado, erro, indisponivel
            $table->unsignedBigInteger('lote_painel_id')->nullable();
            $table->text('erro')->nullable();
            $table->timestamps();
            $table->index(['origem_type', 'origem_id']);
            $table->index(['estado', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envios_painel');
    }
};
