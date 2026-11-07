<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Parâmetros do órgão ajustados pela tela, dentro dos limites de ParametrosOrgao (11.2 e 11.5).
        Schema::create('parametros_orgao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->cascadeOnDelete();
            $table->string('chave', 60);
            $table->string('valor', 500);
            $table->string('justificativa', 500);
            $table->foreignId('alterado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['orgao_id', 'chave']);
        });

        // Permissões extras concedidas a um servidor, com justificativa e revogação (11.4, art. 7º, § 1º).
        Schema::create('concessoes_permissao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('permissao', 80);
            $table->string('justificativa', 1000);
            $table->foreignId('concedida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revogada_em')->nullable();
            $table->foreignId('revogada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_revogacao', 500)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revogada_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concessoes_permissao');
        Schema::dropIfExists('parametros_orgao');
    }
};
