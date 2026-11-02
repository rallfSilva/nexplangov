<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Valores da Lei 14.133 atualizados anualmente por decreto (art. 182). Ficam em tabela para o
        // administrador lançar o decreto de cada ano sem mexer no código.
        Schema::create('valores_limite', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('ano');
            $table->string('dispositivo', 40); // ex.: art75_I, art75_II
            $table->decimal('valor', 15, 2);
            $table->string('ato_normativo');
            $table->boolean('conferido')->default(false);
            $table->timestamps();
            $table->unique(['ano', 'dispositivo']);
        });

        Schema::create('fornecedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->string('tipo_pessoa', 2)->default('PJ'); // PJ, PF
            $table->string('documento', 18); // CNPJ formatado; CPF fica cifrado em documento_cifrado
            $table->text('documento_cifrado')->nullable();
            $table->char('documento_hash', 64); // HMAC do documento: permite buscar e impedir duplicidade sem expor o CPF
            $table->string('razao_social');
            $table->string('nome_fantasia')->nullable();
            $table->string('porte', 10)->nullable(); // ME, EPP, DEMAIS (Lei Complementar 123/2006)
            $table->string('email')->nullable();
            $table->string('telefone', 20)->nullable();
            $table->string('municipio')->nullable();
            $table->string('uf', 2)->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->unique(['orgao_id', 'documento_hash']);
        });

        // Sanções do art. 156 que impedem contratar (impedimento e declaração de inidoneidade).
        Schema::create('sancoes_fornecedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('fornecedor_id')->constrained('fornecedores')->cascadeOnDelete();
            $table->string('tipo', 30); // advertencia, multa, impedimento, inidoneidade
            $table->date('inicio');
            $table->date('fim')->nullable();
            $table->string('processo')->nullable();
            $table->text('fundamento');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sancoes_fornecedor');
        Schema::dropIfExists('fornecedores');
        Schema::dropIfExists('valores_limite');
    }
};
