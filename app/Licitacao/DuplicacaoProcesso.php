<?php

namespace App\Licitacao;

use App\Http\Controllers\ItemProcessoController;
use App\Licitacao\Enums\SituacaoProcesso;
use App\Licitacao\Fluxo\FluxoDocumento;
use App\Models\DocumentoProcesso;
use App\Models\Processo;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Duplica um processo para reaproveitar a instrução de uma contratação deserta, fracassada ou recorrente.
 *
 * Copia os dados, os itens e as cotações (a pesquisa de preços descarta sozinha as que perderam a validade,
 * art. 23, § 1º) e as peças feitas no editor, todas como rascunho de nova versão 1. Não copia o que é ato próprio
 * do processo de origem: parecer jurídico, autorização, documentos de habilitação, arquivos enviados,
 * assinaturas, resultado, propostas e números do PNCP. O novo processo começa na fase preparatória.
 */
final class DuplicacaoProcesso
{
    /** Peças que são atos do processo de origem e não se reaproveitam. */
    public const NAO_COPIAR = ['parecer_juridico', 'autorizacao', 'habilitacao'];

    public function duplicar(Processo $origem, User $u, string $numero, int $ano): Processo
    {
        return DB::transaction(function () use ($origem, $u, $numero, $ano) {
            $novo = $origem->replicate([
                'numero', 'ano', 'numero_compra', 'numero_sei', 'situacao', 'valor_estimado', 'valor_homologado', 'numero_controle_pncp', 'fluxo_id',
                'abertura_propostas_em', 'encerramento_propostas_em', 'sessao_encerrada_em', 'sessao_encerrada_por', 'ata_sessao_caminho', 'ata_sessao_hash', 'sessao_reaberta_motivo',
                'gravacao_link', 'gravacao_caminho', 'gravacao_nome', 'gravacao_hash',
                'origem_id', 'demanda_id', 'created_at', 'updated_at',
            ]);
            $novo->fill(['numero' => $numero, 'ano' => $ano, 'responsavel_id' => $u->id]);
            // O item do PCA só vale para o mesmo exercício; em outro ano, a previsão é indicada de novo (art. 12, VII).
            if ($ano !== (int) $origem->ano) {
                $novo->item_pca_id = null;
            }
            $novo->forceFill(['origem_id' => $origem->id, 'situacao' => SituacaoProcesso::Planejamento->value, 'valor_estimado' => 0])->save();

            foreach ($origem->itens()->with('cotacoes')->get() as $item) {
                $copia = $novo->itens()->create([
                    'orgao_id' => $novo->orgao_id, 'item_pca_id' => $ano === (int) $origem->ano ? $item->item_pca_id : null, 'numero' => $item->numero,
                    'material_servico' => $item->material_servico, 'codigo_catalogo' => $item->codigo_catalogo, 'descricao' => $item->descricao,
                    'unidade_medida' => $item->unidade_medida, 'quantidade' => $item->quantidade, 'metodo_preco' => $item->metodo_preco, 'tipo_beneficio' => $item->tipo_beneficio,
                ]);
                foreach ($item->cotacoes as $c) {
                    $copia->cotacoes()->create($c->only(['fonte', 'referencia', 'documento_fonte', 'valor_unitario', 'data_cotacao', 'desconsiderada', 'motivo_desconsideracao']) + ['orgao_id' => $novo->orgao_id]);
                }
                app(ItemProcessoController::class)->recalcular($copia);
            }

            $pecas = $origem->documentos()->where('origem', 'formulario')->whereNotIn('tipo', self::NAO_COPIAR)->orderByDesc('versao')->get()->unique('tipo');
            foreach ($pecas as $d) {
                DocumentoProcesso::create([
                    'orgao_id' => $novo->orgao_id, 'processo_id' => $novo->id, 'tipo' => $d->tipo, 'versao' => 1, 'conteudo' => $d->conteudo,
                    'situacao' => 'rascunho', 'autor_id' => $u->id, 'origem' => 'formulario', 'modelo_id' => $d->modelo_id, 'modelo_versao' => $d->modelo_versao,
                ]);
            }

            app(FluxoDocumento::class)->aplicar($novo); // fluxo padrão do órgão, como num processo novo

            return $novo->refresh();
        });
    }
}
