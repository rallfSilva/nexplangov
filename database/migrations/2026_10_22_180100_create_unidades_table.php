<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('unidades')->restrictOnDelete();
            $table->string('sigla', 30);
            $table->string('nome');
            $table->string('tipo', 30)->default('departamento');
            $table->foreignId('chefe_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('telefone', 20)->nullable();
            $table->string('codigo_unidade_pncp', 30)->nullable(); // código da unidade compradora no PNCP
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(['orgao_id', 'sigla']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades');
    }
};
