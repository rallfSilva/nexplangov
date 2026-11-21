<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cadastro do PCA (art. 12, VII): abrangência (órgão todo ou unidades participantes), objetivo estratégico,
 * forma de consolidação, unidade responsável, nível de detalhamento e o calendário de elaboração do plano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planos_contratacao', function (Blueprint $table) {
            $table->string('abrangencia', 10)->default('orgao')->after('situacao'); // orgao, unidades
            $table->boolean('possui_objetivo_estrategico')->default(false)->after('abrangencia');
            $table->text('objetivo_estrategico')->nullable()->after('possui_objetivo_estrategico');
            $table->string('tipo_consolidacao', 10)->default('unidade')->after('objetivo_estrategico'); // unidade, setor
            $table->foreignId('unidade_responsavel_id')->nullable()->after('tipo_consolidacao')->constrained('unidades')->nullOnDelete();
            $table->string('nivel_detalhamento', 10)->default('livre')->after('unidade_responsavel_id'); // item, classe, livre
            foreach (['inicio_demandas', 'fim_dfds', 'fim_aprovacao', 'fim_revisao', 'fim_consolidacao', 'fim_divulgacao'] as $c) {
                $table->date($c)->nullable();
            }
        });

        Schema::create('pca_participantes', function (Blueprint $table) {
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('plano_contratacao_id')->constrained('planos_contratacao')->cascadeOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->cascadeOnDelete();
            $table->primary(['plano_contratacao_id', 'unidade_id']);
        });

        // Itens que o PNCP já tem em cada plano de unidade e o resultado da última conferência com o portal.
        Schema::table('pcas_unidade', function (Blueprint $table) {
            $table->json('itens_publicados')->nullable()->after('numero_controle_pncp');
            $table->json('sincronizacao')->nullable()->after('itens_publicados');
            $table->timestamp('sincronizado_em')->nullable()->after('sincronizacao');
        });
    }

    public function down(): void
    {
        Schema::table('pcas_unidade', fn (Blueprint $table) => $table->dropColumn(['itens_publicados', 'sincronizacao', 'sincronizado_em']));
        Schema::dropIfExists('pca_participantes');
        Schema::table('planos_contratacao', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unidade_responsavel_id');
            $table->dropColumn(['abrangencia', 'possui_objetivo_estrategico', 'objetivo_estrategico', 'tipo_consolidacao', 'nivel_detalhamento',
                'inicio_demandas', 'fim_dfds', 'fim_aprovacao', 'fim_revisao', 'fim_consolidacao', 'fim_divulgacao']);
        });
    }
};
