<?php

namespace App\Http\Controllers;

use App\Licitacao\Ia\AssistenteIa;
use App\Licitacao\Ia\IaIndisponivel;
use App\Licitacao\Regras\ConsolidacaoPca;
use App\Licitacao\RegraViolada;
use App\Models\Contrato;
use App\Models\PlanoContratacao;
use App\Models\Processo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Recomendações da NexMind IA fora das peças (T2): no PCA, na pesquisa de preços e na gestão do contrato.
 * Só dados do próprio órgão, sem dado pessoal (Anonimizador). A resposta é apoio; não altera nada.
 */
class RecomendacaoIaController extends Controller
{
    public function __construct(private AssistenteIa $ia) {}

    public function pca(Request $request, PlanoContratacao $plano): RedirectResponse
    {
        $plano->load('itens.unidade');
        $linhas = ["PCA {$plano->ano}, situação {$plano->situacao}, ".$plano->itens->count().' itens, total R$ '.$this->reais($plano->itens->sum('valor_total_estimado')).'.'];
        foreach ($plano->itens->sortByDesc('valor_total_estimado')->take(40) as $i) {
            $linhas[] = "Item {$i->numero} ({$i->unidade?->sigla}): {$i->descricao}; natureza {$i->natureza_despesa}; R$ ".$this->reais($i->valor_total_estimado).'; desejado em '.($i->data_desejada?->format('m/Y') ?? 'sem data').'.';
        }
        $grupos = ConsolidacaoPca::sugestoes($plano->itens->map(fn ($i) => ['id' => $i->id, 'numero' => (int) $i->numero, 'unidade' => $i->unidade?->sigla, 'tipo' => $i->material_servico, 'codigo' => $i->codigo_catalogo,
            'classe' => null, 'natureza' => $i->natureza_despesa, 'descricao' => $i->descricao, 'valor' => (float) $i->valor_total_estimado, 'data' => $i->data_desejada?->toDateString()])->all());
        foreach ($grupos as $g) {
            $linhas[] = 'Agrupamento já apontado pelo sistema: '.$g['motivo'];
        }

        return $this->responder($request, 'pca', implode("\n", $linhas), $plano->orgao_id, null, redirect()->route('pca.consolidacao', $plano));
    }

    public function precos(Request $request, Processo $processo): RedirectResponse
    {
        $linhas = ["Processo {$processo->identificacao()}. Objeto: {$processo->objeto}"];
        foreach ($processo->itens()->with('cotacoes')->get() as $i) {
            $linhas[] = "Item {$i->numero}: {$i->descricao} ({$i->quantidade} {$i->unidade_medida}), método {$i->metodo_preco}, estimado R$ ".$this->reais($i->valor_unitario_estimado).'.';
            foreach ($i->cotacoes as $c) {
                $linhas[] = "  - Fonte {$c->fonte}, R$ ".$this->reais($c->valor_unitario).", em {$c->data_cotacao?->format('d/m/Y')}".($c->desconsiderada ? ' (desconsiderada: '.$c->motivo_desconsideracao.')' : '').'.';
            }
        }

        return $this->responder($request, 'precos', implode("\n", $linhas), $processo->orgao_id, $processo->id, redirect()->route('processos.show', [$processo, 'aba' => 'itens']));
    }

    public function contrato(Request $request, Contrato $contrato): RedirectResponse
    {
        $c = $contrato->load(['termos', 'medicoes', 'ocorrencias']);
        $linhas = ["Contrato {$c->identificacao()}. Objeto: {$c->objeto}", 'Vigência de '.$c->vigencia_inicio?->format('d/m/Y').' a '.$c->vigencia_fim?->format('d/m/Y').'. Valor inicial R$ '.$this->reais($c->valor_inicial).'; valor atualizado R$ '.$this->reais($c->valorAtualizadoTotal()).'.',
            'Serviço contínuo: '.($c->servico_continuo ? 'sim' : 'não').'. Situação: '.$c->situacao.'.'];
        foreach ($c->termos->take(20) as $t) {
            $linhas[] = "Termo {$t->numero} ({$t->tipo}): acréscimo R$ ".$this->reais($t->valor_acrescimo).', supressão R$ '.$this->reais($t->valor_supressao).', reajuste R$ '.$this->reais($t->valor_reajuste).'.';
        }
        foreach ($c->medicoes->take(20) as $m) {
            $linhas[] = "Medição {$m->numero}: R$ ".$this->reais($m->valor).", {$m->situacao}.";
        }
        foreach ($c->ocorrencias->take(20) as $o) {
            $prazo = $o->prazo_regularizacao ? ' · prazo para regularizar '.Carbon::parse($o->prazo_regularizacao)->format('d/m/Y') : '';
            $linhas[] = "Ocorrência {$o->numero} ({$o->tipo}): ".mb_substr((string) $o->descricao, 0, 300).$prazo.'.';
        }

        return $this->responder($request, 'contrato', implode("\n", $linhas), $c->orgao_id, $c->processo_id, redirect()->route('contratos.show', $c));
    }

    private function responder(Request $request, string $etapa, string $contexto, int $orgaoId, ?int $processoId, RedirectResponse $volta): RedirectResponse
    {
        if (AssistenteIa::limiteAtingido($request->user())) {
            return $volta->with('erro', 'Você chegou ao limite diário de uso da IA. Amanhã o limite renova.');
        }
        try {
            $texto = $this->ia->recomendar($etapa, $contexto, $orgaoId, $processoId, $request->user());
        } catch (RegraViolada $e) {
            return $volta->with('erro', implode(' ', $e->motivos));
        } catch (IaIndisponivel $e) {
            return $volta->with('erro', $e->getMessage());
        }

        return $volta->with('recomendacao_ia', $texto);
    }

    private function reais(mixed $v): string
    {
        return number_format((float) $v, 2, ',', '.');
    }
}
