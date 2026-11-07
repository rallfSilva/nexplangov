<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Nota de empenho e pedido de compra na liquidação (6.4; Lei 4.320, arts. 58 a 63; Lei 14.133, art. 92, VIII, e art. 141). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empenhos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('fornecedor_id')->constrained('fornecedores')->restrictOnDelete();
            $table->foreignId('contrato_id')->nullable()->constrained('contratos')->restrictOnDelete();
            $table->foreignId('ata_id')->nullable()->constrained('atas')->restrictOnDelete();
            $table->string('numero', 30);
            $table->unsignedSmallInteger('exercicio');
            $table->string('tipo', 12); // ordinario, estimativo, global
            $table->date('emitido_em');
            $table->string('natureza_despesa', 20);
            $table->string('fonte_recurso', 120);
            $table->decimal('valor', 16, 2);
            $table->decimal('valor_anulado', 16, 2)->default(0);
            $table->string('motivo_anulacao', 500)->nullable();
            $table->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['orgao_id', 'exercicio', 'numero']);
            $table->index(['orgao_id', 'fornecedor_id']);
        });

        Schema::table('liquidacoes', function (Blueprint $table) {
            $table->foreignId('empenho_id')->nullable()->after('ordem_id')->constrained('empenhos')->restrictOnDelete();
            $table->string('pedido_compra', 60)->nullable()->after('empenho_id');
        });
    }

    public function down(): void
    {
        Schema::table('liquidacoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('empenho_id');
            $table->dropColumn('pedido_compra');
        });
        Schema::dropIfExists('empenhos');
    }
};
