<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PCA publicado no PNCP por unidade compradora (art. 12, § 1º, e art. 174) e DFD com itens do catálogo
 * CATMAT/CATSER (art. 12, VII, e art. 19, II), consolidados nos itens do plano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcas_unidade', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('plano_contratacao_id')->constrained('planos_contratacao')->cascadeOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->string('numero_controle_pncp')->nullable();
            $table->timestamps();
            $table->unique(['plano_contratacao_id', 'unidade_id']);
        });

        Schema::create('itens_demanda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('demanda_id')->constrained('demandas')->cascadeOnDelete();
            $table->foreignId('item_pca_id')->nullable()->constrained('itens_pca')->nullOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->char('material_servico', 1); // M = CATMAT, S = CATSER
            $table->unsignedTinyInteger('categoria');
            $table->string('codigo_catalogo', 20)->nullable();
            $table->string('descricao', 2000);
            $table->string('unidade_fornecimento', 30);
            $table->decimal('quantidade', 15, 4);
            $table->decimal('valor_unitario_estimado', 15, 4);
            $table->decimal('valor_total_estimado', 15, 2);
            $table->timestamps();
            $table->unique(['demanda_id', 'numero']);
        });

        Schema::table('demandas', function (Blueprint $table) {
            $table->timestamp('enviada_em')->nullable()->after('situacao');
            $table->timestamp('consolidada_em')->nullable()->after('enviada_em');
        });

        // O PCA já publicado como um só fica com a unidade que o envio antigo usava.
        foreach (DB::table('planos_contratacao')->whereNotNull('numero_controle_pncp')->get() as $p) {
            $primeiro = DB::table('itens_pca')->leftJoin('unidades', 'unidades.id', '=', 'itens_pca.unidade_id')
                ->where('itens_pca.plano_contratacao_id', $p->id)->orderBy('itens_pca.numero')->first(['unidades.id', 'unidades.codigo_unidade_pncp']);
            $unidade = ($primeiro?->codigo_unidade_pncp ? $primeiro->id : null)
                ?? DB::table('unidades')->where('orgao_id', $p->orgao_id)->whereNotNull('codigo_unidade_pncp')->orderBy('id')->value('id');
            if ($unidade) {
                $parte = DB::table('pcas_unidade')->insertGetId(['orgao_id' => $p->orgao_id, 'plano_contratacao_id' => $p->id, 'unidade_id' => $unidade,
                    'numero_controle_pncp' => $p->numero_controle_pncp, 'created_at' => now(), 'updated_at' => now()]);
                // O histórico de envios passa a apontar a parte da unidade, para retificar e excluir por ela.
                DB::table('envios_pncp')->where('recurso', 'pca')->where('publicavel_type', 'App\\Models\\PlanoContratacao')->where('publicavel_id', $p->id)
                    ->update(['publicavel_type' => 'App\\Models\\PcaUnidade', 'publicavel_id' => $parte]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('demandas', fn (Blueprint $table) => $table->dropColumn(['enviada_em', 'consolidada_em']));
        Schema::dropIfExists('itens_demanda');
        Schema::dropIfExists('pcas_unidade');
    }
};
