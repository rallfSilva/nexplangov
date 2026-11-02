<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orgaos', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('sigla', 30)->unique();
            $table->string('cnpj', 18)->nullable()->unique();
            $table->string('esfera', 20)->default('municipal');
            $table->string('poder', 20)->default('executivo');
            $table->unsignedSmallInteger('natureza_juridica')->nullable(); // código RFB/PNCP, ex.: 1031, 1066, 1120
            $table->string('uf', 2)->nullable();
            $table->string('municipio')->nullable();
            // Art. 75, § 2º: limites da dispensa em dobro para consórcio público e agência executiva.
            $table->boolean('limite_dispensa_em_dobro')->default(false);
            $table->string('pncp_ambiente', 12)->default('homologacao');
            $table->boolean('pncp_orgao_preparado')->default(false);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('orgao_id')->nullable()->after('id')->constrained('orgaos')->restrictOnDelete();
            $table->string('cpf', 14)->nullable()->unique()->after('email');
            $table->boolean('ativo')->default(true)->after('password');
            $table->timestamp('ultimo_acesso_em')->nullable()->after('ativo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('orgao_id');
            $table->dropColumn(['cpf', 'ativo', 'ultimo_acesso_em']);
        });

        Schema::dropIfExists('orgaos');
    }
};
