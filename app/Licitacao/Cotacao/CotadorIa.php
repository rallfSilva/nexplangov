<?php

namespace App\Licitacao\Cotacao;

use App\Http\Controllers\ItemProcessoController;
use App\Integracoes\ComprasGov\PesquisaPrecosComprasGov;
use App\Integracoes\IntegracaoIndisponivel;
use App\Integracoes\NotasFiscais\PrecosNotasFiscais;
use App\Integracoes\Pncp\PrecosPncp;
use App\Licitacao\Enums\FontePreco;
use App\Licitacao\Ia\AssistenteIa;
use App\Licitacao\Regras\CotacaoAutomatica;
use App\Models\CatalogoItem;
use App\Models\ItemProcesso;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cota um item com a NexMind IA, usando três fontes públicas de preços já praticados (art. 23, § 1º):
 * Painel de Preços do governo federal (inciso I), licitações anteriores de outros órgãos no PNCP (inciso II) e
 * notas fiscais eletrônicas (inciso V, quando o órgão tem o convênio de acesso). Passos:
 *
 * 1. Sem código CATMAT/CATSER, a IA escolhe o código entre os itens parecidos do catálogo (art. 19, II).
 * 2. Busca os preços do último ano nas três fontes.
 * 3. A IA marca quais são de objeto equivalente e escreve a justificativa; a regra escolhe os N mais recentes.
 * 4. Grava as cotações com a fonte e o motivo, troca as cotações anteriores da IA e recalcula o preço do item.
 *
 * As cotações lançadas à mão nunca são apagadas. Tudo fica na auditoria e o servidor pode desconsiderar
 * ou excluir qualquer cotação depois.
 */
final class CotadorIa
{
    public function __construct(private AssistenteIa $ia, private PesquisaPrecosComprasGov $comprasGov, private PrecosPncp $pncp, private PrecosNotasFiscais $notas) {}

    /** @return array{situacao: string, mensagem: string, gravadas: int} */
    public function cotar(ItemProcesso $item, int $quantidade, User $u): array
    {
        $termos = null;
        if (! $item->codigo_catalogo) {
            $escolha = $this->ia->cotarCatalogo($item, $this->candidatosCatalogo($item), $u);
            $termos = $escolha['termos'];
            if ($escolha['codigo']) {
                $item->update(['codigo_catalogo' => $escolha['codigo']]);
            }
        }
        $termos ??= implode(' ', array_slice(CotacaoAutomatica::palavrasChave($item->descricao), 0, 3));

        [$candidatos, $falhas] = $this->coletar($item, $termos);
        if ($candidatos === []) {
            return ['situacao' => 'sem_resultado', 'mensagem' => 'Nenhum preço do último ano encontrado'.($falhas ? ' ('.implode(' ', $falhas).')' : '').'. Lance as cotações à mão ou ajuste a descrição.', 'gravadas' => 0];
        }

        $avaliacao = $this->ia->cotarAvaliar($item, $candidatos, $u);
        $escolhidos = CotacaoAutomatica::selecionar($candidatos, $avaliacao['compativeis'], $quantidade);
        if ($escolhidos === []) {
            return ['situacao' => 'sem_resultado', 'mensagem' => count($candidatos).' preço(s) encontrado(s), mas nenhum de objeto equivalente ao item. Confira a descrição ou cote à mão.', 'gravadas' => 0];
        }

        $consultadoEm = now();
        DB::transaction(function () use ($item, $candidatos, $escolhidos, $avaliacao, $consultadoEm) {
            $item->cotacoes()->where('origem', 'ia')->get()->each->delete();
            foreach ($escolhidos as $i) {
                $c = $candidatos[$i];
                $item->cotacoes()->create([
                    'orgao_id' => $item->orgao_id,
                    'fonte' => FontePreco::from($c['fonte'] ?? FontePreco::ContratacoesSimilares->value)->value,
                    'referencia' => mb_substr("{$c['origem']} · {$c['orgao']}".($c['uasg'] ? " (UASG {$c['uasg']})" : ''), 0, 255),
                    'documento_fonte' => $c['cnpj'],
                    'valor_unitario' => $c['valor'],
                    'data_cotacao' => $c['data'],
                    'origem' => 'ia',
                    'quantidade' => $c['quantidade'],
                    'unidade_fonte' => $c['unidade'] ? mb_substr($c['unidade'], 0, 60) : null,
                    'descricao_fonte' => $c['descricao'],
                    'url_fonte' => mb_substr($c['url'], 0, 500),
                    'identificacao_fonte' => $c['identificacao'],
                    'consultado_em' => $consultadoEm,
                    'analise_ia' => $avaliacao['compativeis'][$i] ?? null,
                ]);
            }
            if ($avaliacao['justificativa']) {
                $item->update(['justificativa_precos' => $avaliacao['justificativa']]);
            }
        });
        app(ItemProcessoController::class)->recalcular($item->fresh());

        $n = count($escolhidos);

        return ['situacao' => 'cotado', 'mensagem' => "{$n} preço(s) de ".count($candidatos).' encontrado(s)'.($n < $quantidade ? ", menos que os {$quantidade} pedidos" : '').'.'.($falhas ? ' '.implode(' ', $falhas) : ''), 'gravadas' => $n];
    }

    /**
     * Preços das duas fontes, já normalizados, sem repetição e limitados para a IA avaliar.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function coletar(ItemProcesso $item, string $termos): array
    {
        $falhas = [];
        $lista = [];

        if ($item->codigo_catalogo) {
            try {
                $lista = $this->comprasGov->buscar($item->material_servico, $item->codigo_catalogo);
            } catch (IntegracaoIndisponivel $e) {
                $falhas[] = $e->getMessage();
            }
        }

        try {
            $lista = array_merge($lista, $this->pncp->buscar($termos, $item->descricao));
        } catch (IntegracaoIndisponivel $e) {
            $falhas[] = $e->getMessage();
        }

        if (PrecosNotasFiscais::habilitada()) {
            try {
                $lista = array_merge($lista, $this->notas->buscar($item->descricao, $item->codigo_catalogo));
            } catch (IntegracaoIndisponivel $e) {
                $falhas[] = $e->getMessage();
            }
        }

        $vistos = [];
        $saida = [];
        foreach ($lista as $p) {
            if (empty($p['url']) || empty($p['identificacao'])) { // sem fonte verificável, o preço não entra
                continue;
            }
            $chave = $p['origem'].'|'.$p['id'].'|'.$p['data'].'|'.$p['valor'];
            if (! isset($vistos[$chave])) {
                $vistos[$chave] = true;
                $saida[] = $p;
            }
        }
        usort($saida, fn ($a, $b) => strcmp($b['data'], $a['data']));

        // As três fontes entram intercaladas, para nenhuma ocupar sozinha a lista que vai para a IA.
        return [CotacaoAutomatica::intercalar($saida, (int) config('nexplangov.cotacao_ia.max_candidatos', 30)), $falhas];
    }

    /** @return list<array{codigo: string, descricao: string}> */
    private function candidatosCatalogo(ItemProcesso $item): array
    {
        $palavras = CotacaoAutomatica::palavrasChave($item->descricao);
        if ($palavras === []) {
            return [];
        }
        $q = CatalogoItem::query()->where('tipo', $item->material_servico)->where('ativo', true)
            ->where(function ($q) use ($palavras) {
                foreach (array_slice($palavras, 0, 3) as $p) {
                    $q->orWhere('descricao', 'ilike', '%'.addcslashes($p, '%_\\').'%');
                }
            })
            ->limit(200)->get(['codigo', 'descricao']);

        return $q->map(fn ($c) => ['codigo' => (string) $c->codigo, 'descricao' => Str::limit($c->descricao, 250), 's' => CotacaoAutomatica::semelhanca($palavras, $c->descricao)])
            ->filter(fn ($c) => $c['s'] > 0)
            ->sortByDesc('s')->take(15)
            ->map(fn ($c) => ['codigo' => $c['codigo'], 'descricao' => $c['descricao']])
            ->values()->all();
    }
}
