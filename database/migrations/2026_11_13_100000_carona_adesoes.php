<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adesão a ata de registro de preços por órgão não participante, a "carona" (art. 86, §§ 2º a 8º):
 * pedido público com protocolo, aceite do fornecedor, prazo para contratar e autorização com QR Code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ata_adesoes', function (Blueprint $table) {
            $table->string('protocolo', 20)->nullable()->unique();
            $table->string('origem', 10)->default('interno'); // interno (registrado pelo gerenciador) ou publico
            $table->string('aderente_esfera', 20)->default('municipal');
            $table->string('aderente_uf', 2)->nullable();
            $table->string('aderente_municipio', 120)->nullable();
            $table->string('solicitante_nome', 160)->nullable();
            $table->string('solicitante_cargo', 120)->nullable();
            $table->string('solicitante_email', 160)->nullable();
            $table->string('oficio_caminho')->nullable();
            $table->string('oficio_hash', 64)->nullable();
            // Aceite do fornecedor beneficiário (art. 86, § 2º, III).
            $table->string('fornecedor_aceite', 10)->nullable(); // aceito ou recusado
            $table->timestamp('fornecedor_aceite_em')->nullable();
            $table->string('fornecedor_aceite_representante', 160)->nullable();
            $table->string('fornecedor_aceite_obs', 500)->nullable();
            $table->string('fornecedor_aceite_via', 10)->nullable(); // link (o próprio fornecedor) ou registro (servidor, com documento)
            $table->string('fornecedor_aceite_caminho')->nullable();
            $table->foreignId('fornecedor_aceite_registrado_por')->nullable()->constrained('users')->nullOnDelete();
            // Autorização do gerenciador.
            $table->date('prazo_contratacao')->nullable();
            $table->string('codigo_verificacao', 24)->nullable()->unique();
            $table->string('autorizacao_caminho')->nullable();
            $table->string('autorizacao_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ata_adesoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fornecedor_aceite_registrado_por');
            $table->dropColumn(['protocolo', 'origem', 'aderente_esfera', 'aderente_uf', 'aderente_municipio', 'solicitante_nome', 'solicitante_cargo', 'solicitante_email',
                'oficio_caminho', 'oficio_hash', 'fornecedor_aceite', 'fornecedor_aceite_em', 'fornecedor_aceite_representante', 'fornecedor_aceite_obs', 'fornecedor_aceite_via',
                'fornecedor_aceite_caminho', 'prazo_contratacao', 'codigo_verificacao', 'autorizacao_caminho', 'autorizacao_hash']);
        });
    }
};
