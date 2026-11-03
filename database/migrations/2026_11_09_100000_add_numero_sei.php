<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Número do processo no SEI (ou outro sistema de protocolo) para ligar os dois registros.
        Schema::table('processos', function (Blueprint $table) {
            $table->string('numero_sei', 40)->nullable()->after('numero_compra');
        });
    }

    public function down(): void
    {
        Schema::table('processos', function (Blueprint $table) {
            $table->dropColumn('numero_sei');
        });
    }
};
