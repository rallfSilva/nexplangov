<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versões do PCA (art. 12, VII e § 1º): cada aprovação guarda uma cópia dos itens. Depois de aprovado,
 * o plano só muda por revisão aberta com justificativa e aprovada de novo pela autoridade competente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versoes_pca', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos')->restrictOnDelete();
            $table->foreignId('plano_contratacao_id')->constrained('planos_contratacao')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->text('justificativa')->nullable();
            $table->json('itens');
            $table->unsignedInteger('qtd_itens');
            $table->decimal('valor_total', 17, 2);
            $table->foreignId('aberta_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('aprovado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprovado_em')->nullable();
            $table->timestamps();
            $table->unique(['plano_contratacao_id', 'numero']);
        });

        Schema::table('planos_contratacao', function (Blueprint $table) {
            $table->unsignedSmallInteger('versao_atual')->default(0)->after('situacao');
            $table->text('revisao_justificativa')->nullable()->after('versao_atual');
            $table->foreignId('revisao_aberta_por')->nullable()->after('revisao_justificativa')->constrained('users')->nullOnDelete();
            $table->timestamp('revisao_aberta_em')->nullable()->after('revisao_aberta_por');
        });

        // Planos já aprovados ganham a versão 1 com os itens de hoje.
        $campos = ['numero', 'material_servico', 'categoria', 'codigo_catalogo', 'descricao', 'unidade_fornecimento', 'quantidade', 'valor_unitario_estimado', 'valor_total_estimado', 'unidade_id', 'data_desejada', 'prioridade', 'natureza_despesa'];
        foreach (DB::table('planos_contratacao')->where('situacao', 'aprovado')->get() as $p) {
            $itens = DB::table('itens_pca')->where('plano_contratacao_id', $p->id)->orderBy('numero')->get($campos)->map(fn ($i) => (array) $i)->all();
            DB::table('versoes_pca')->insert([
                'orgao_id' => $p->orgao_id, 'plano_contratacao_id' => $p->id, 'numero' => 1, 'justificativa' => null,
                'itens' => json_encode($itens), 'qtd_itens' => count($itens), 'valor_total' => array_sum(array_column($itens, 'valor_total_estimado')),
                'aprovado_por' => $p->aprovado_por, 'aprovado_em' => $p->aprovado_em, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('planos_contratacao')->where('id', $p->id)->update(['versao_atual' => 1]);
        }
    }

    public function down(): void
    {
        Schema::table('planos_contratacao', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revisao_aberta_por');
            $table->dropColumn(['versao_atual', 'revisao_justificativa', 'revisao_aberta_em']);
        });
        Schema::dropIfExists('versoes_pca');
    }
};
