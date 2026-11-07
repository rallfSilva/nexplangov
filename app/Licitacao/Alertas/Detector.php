<?php

namespace App\Licitacao\Alertas;

use App\Licitacao\Regras\EficaciaContrato;
use App\Licitacao\Regras\ParametrosOrgao;
use App\Licitacao\TiposDocumento;
use App\Models\Ata;
use App\Models\Contrato;
use App\Models\EtapaProcesso;
use App\Models\Impugnacao;
use App\Models\OrdemFornecimento;
use App\Models\User;
use App\Support\Parametros;
use Carbon\CarbonInterface;

/**
 * Descobre o que pede atenção: vigência de contrato e de ata (art. 106 e art. 84), prazo de peça da instrução,
 * resposta a impugnação (art. 164) e entrega atrasada. Só lê dados; quem envia é o EnviadorDeAlertas.
 *
 * Cada alerta: ['chave', 'orgao_id', 'users' => [ids], 'titulo', 'texto', 'url', 'urgente'].
 */
final class Detector
{
    private const ENCERRADOS = ['contratado', 'revogado', 'anulado', 'deserto', 'fracassado'];

    /** @return list<array{chave: string, orgao_id: int, users: list<int>, titulo: string, texto: string, url: string, urgente: bool}> */
    public function detectar(?CarbonInterface $hoje = null): array
    {
        $hoje = ($hoje ?? today())->copy()->startOfDay();

        return array_values(array_merge($this->contratos($hoje), $this->publicacaoContratos($hoje), $this->atas($hoje), $this->etapas($hoje), $this->impugnacoes($hoje), $this->ordens($hoje)));
    }

    /** @return list<array<string, mixed>> */
    public function paraUsuario(User $u, ?CarbonInterface $hoje = null): array
    {
        return array_values(array_filter($this->detectar($hoje), fn ($a) => in_array($u->id, $a['users'], true)));
    }

    /** Menor marco que já foi alcançado, ou null. Marcos em dias antes do fim; 0 = vencido. */
    public static function marcoAtingido(int $dias, array $marcos): ?int
    {
        if ($dias < 0) {
            return 0;
        }
        $m = collect($marcos)->sort()->first(fn ($x) => $dias <= $x);

        return $m === null ? null : (int) $m;
    }

    private function contratos(CarbonInterface $hoje): array
    {
        // Cada órgão define os seus marcos (Parâmetros); a busca cobre o maior marco permitido.
        $limite = $hoje->copy()->addDays(ParametrosOrgao::DEFINICOES['alertas_vigencia']['max']);
        $r = [];
        $marcos = [];

        foreach (Contrato::with('fornecedor')->where('situacao', 'vigente')->whereDate('vigencia_fim', '<=', $limite)->get() as $c) {
            $dias = (int) $hoje->diffInDays($c->vigencia_fim->copy()->startOfDay(), false);
            $marco = self::marcoAtingido($dias, $marcos[$c->orgao_id] ??= Parametros::valor('alertas_vigencia', $c->orgao_id));
            if ($marco === null) {
                continue;
            }
            $r[] = $this->alerta("contrato:{$c->id}:{$marco}", $c->orgao_id, array_filter([$c->gestor_id, $c->fiscal_id]) ?: $this->com($c->orgao_id, 'contratos.gerenciar'),
                $dias < 0 ? "Contrato {$c->identificacao()} com vigência vencida" : "Contrato {$c->identificacao()} vence em {$dias} dia(s)",
                "{$c->fornecedor->razao_social}. Vigência até {$c->vigencia_fim->format('d/m/Y')}. Decida sobre prorrogação ou nova contratação a tempo (art. 107).",
                route('contratos.show', $c, false), $dias <= 30);
        }

        return $r;
    }

    /** Contrato sem divulgação no PNCP: alerta enquanto corre o prazo do art. 94 e urgente perto do fim ou vencido. */
    private function publicacaoContratos(CarbonInterface $hoje): array
    {
        $r = [];

        foreach (Contrato::with(['fornecedor', 'processo'])->where('situacao', 'vigente')->whereNull('numero_controle_pncp')->get() as $c) {
            $e = $c->eficacia($hoje);
            $vencido = $e['estado'] === 'vencido';
            $urgente = $vencido || $e['restantes'] <= EficaciaContrato::DIAS_ALERTA;
            $users = array_merge(array_filter([$c->gestor_id]), $this->com($c->orgao_id, 'pncp.enviar'));

            $r[] = $this->alerta("pncp94:{$c->id}:".($vencido ? 'vencido' : ($urgente ? 'urgente' : 'aberto')), $c->orgao_id, $users,
                $vencido ? "Contrato {$c->identificacao()} sem publicação no PNCP: prazo vencido" : "Contrato {$c->identificacao()} precisa ir ao PNCP até {$e['limite']->format('d/m/Y')}",
                $vencido
                    ? "O prazo terminou em {$e['limite']->format('d/m/Y')} ({$e['base']}). Sem a divulgação, o contrato não tem eficácia e não pode ser executado nem pago."
                    : "Faltam {$e['restantes']} dia(s) útil(eis) ({$e['base']}). Até a divulgação, o contrato não tem eficácia: medição, liquidação e pagamento ficam bloqueados.",
                route('contratos.show', $c, false), $urgente);
        }

        return $r;
    }

    private function atas(CarbonInterface $hoje): array
    {
        $marcos = [60, 30, 15, 7];
        $r = [];

        foreach (Ata::with('fornecedor')->where('situacao', 'vigente')->whereDate('vigencia_fim', '<=', $hoje->copy()->addDays(60))->whereDate('vigencia_fim', '>=', $hoje)->get() as $a) {
            $dias = (int) $hoje->diffInDays($a->vigencia_fim->copy()->startOfDay(), false);
            $marco = self::marcoAtingido($dias, $marcos);
            if ($marco === null) {
                continue;
            }
            $r[] = $this->alerta("ata:{$a->id}:{$marco}", $a->orgao_id, $this->com($a->orgao_id, 'contratos.gerenciar'), "Ata {$a->identificacao()} vence em {$dias} dia(s)",
                "{$a->fornecedor->razao_social}. A vigência é de 1 ano, prorrogável uma vez até 2 anos se o preço seguir vantajoso (art. 84).", route('atas.show', $a, false), $dias <= 15);
        }

        return $r;
    }

    private function etapas(CarbonInterface $hoje): array
    {
        $r = [];

        foreach (EtapaProcesso::with('processo')->whereNotNull('prazo_em')->whereDate('prazo_em', '<=', $hoje->copy()->addDay())->get() as $e) {
            $p = $e->processo;
            if (! $p || in_array($p->situacao, self::ENCERRADOS, true) || $p->documentoConcluido($e->tipo)) {
                continue;
            }
            $vencida = $e->prazo_em->lt($hoje);
            $def = TiposDocumento::get($e->tipo);
            $r[] = $this->alerta('etapa:'.$e->id.':'.($vencida ? 'vencida' : 'd1'), $e->orgao_id, array_filter([$e->responsavel_id, $e->substituto_id]),
                ($vencida ? 'Prazo vencido: ' : 'Prazo amanhã: ').($def['rotulo'] ?? $e->tipo), "Processo {$p->identificacao()}. Prazo da peça: {$e->prazo_em->format('d/m/Y')}.", route('documentos.edit', [$p, $e->tipo], false), $vencida);
        }

        return $r;
    }

    private function impugnacoes(CarbonInterface $hoje): array
    {
        $r = [];

        foreach (Impugnacao::with('processo')->where('situacao', 'recebida')->whereNotNull('prazo_resposta_em')->whereDate('prazo_resposta_em', '<=', $hoje->copy()->addDay())->get() as $i) {
            $vencida = $i->prazo_resposta_em->lt($hoje);
            $users = array_values(array_unique(array_merge(array_filter([$i->processo?->responsavel_id]), $this->com($i->orgao_id, 'licitacao.conduzir'))));
            $r[] = $this->alerta('impugnacao:'.$i->id.':'.($vencida ? 'vencida' : 'd1'), $i->orgao_id, $users, ($vencida ? 'Impugnação sem resposta no prazo' : 'Impugnação a responder até amanhã'),
                "Protocolo {$i->protocolo}, processo {$i->processo?->identificacao()}. Prazo: {$i->prazo_resposta_em->format('d/m/Y')} (art. 164: 3 dias úteis).", route('impugnacoes.index', $i->processo, false), $vencida);
        }

        return $r;
    }

    private function ordens(CarbonInterface $hoje): array
    {
        $r = [];

        foreach (OrdemFornecimento::with('ataItem.ata.fornecedor')->whereIn('situacao', ['emitida', 'parcial'])->whereDate('prazo_entrega', '<=', $hoje->copy()->addDay())->get() as $o) {
            $atrasada = $o->prazo_entrega->lt($hoje);
            $r[] = $this->alerta('ordem:'.$o->id.':'.($atrasada ? 'atrasada' : 'd1'), $o->orgao_id, $this->com($o->orgao_id, 'contratos.gerenciar'),
                ($atrasada ? 'Entrega atrasada: ordem ' : 'Entrega prevista para amanhã: ordem ').$o->identificacao(),
                "{$o->ataItem->ata->fornecedor->razao_social}. Prazo: {$o->prazo_entrega->format('d/m/Y')}. Atraso injustificado pode gerar sanção (art. 155).", route('ordens.show', $o, false), $atrasada);
        }

        return $r;
    }

    private function alerta(string $chave, int $orgaoId, array $users, string $titulo, string $texto, string $url, bool $urgente): array
    {
        return ['chave' => $chave, 'orgao_id' => $orgaoId, 'users' => array_values(array_unique(array_map('intval', $users))), 'titulo' => $titulo, 'texto' => $texto, 'url' => $url, 'urgente' => $urgente];
    }

    /** @var array<string, list<int>> */
    private array $cache = [];

    /** Usuários ativos do órgão com a permissão. */
    private function com(int $orgaoId, string $permissao): array
    {
        return $this->cache["$orgaoId:$permissao"] ??= User::permission($permissao)->where('orgao_id', $orgaoId)->where('ativo', true)->pluck('id')->all();
    }
}
