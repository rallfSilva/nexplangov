<?php

namespace App\Licitacao\Contratos;

use App\Licitacao\Regras\NotaEmpenho;
use App\Licitacao\Regras\OrdemCronologica;
use App\Licitacao\RegraViolada;
use App\Models\Ata;
use App\Models\Contrato;
use App\Models\Empenho;
use App\Models\Liquidacao;
use App\Models\Medicao;
use App\Models\OrdemFornecimento;
use App\Models\User;
use App\Support\Parametros;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Do executado ao pago: nota de empenho (Lei 4.320, art. 60) → medição pelo fiscal → aprovação pelo gestor
 * (recebimento, art. 140) → liquidação contra o empenho, com nota fiscal e atesto → pagamento na ordem cronológica da fonte e da categoria (art. 141), com alteração
 * só por justificativa prévia da autoridade (§ 1º) e comunicação posterior ao controle interno e ao tribunal.
 */
final class Pagamentos
{
    public function __construct(private OrdemCronologica $regras = new OrdemCronologica) {}

    public function medir(Contrato $c, string $inicio, string $fim, float $valor, string $descricao, User $u): Medicao
    {
        $c->exigirEficacia('registrar medição'); // art. 94: sem divulgação no PNCP, o contrato não produz efeitos
        $m = [];
        if ($fim < $inicio) {
            $m[] = 'O fim do período medido não pode ser anterior ao início.';
        }
        if ($inicio < $c->vigencia_inicio->toDateString() || $fim > $c->vigencia_fim->toDateString()) {
            $m[] = 'O período medido precisa estar dentro da vigência do contrato.';
        }
        if ($valor <= 0) {
            $m[] = 'O valor medido precisa ser maior que zero.';
        }
        if (mb_strlen(trim($descricao)) < 20) {
            $m[] = 'Descreva o que foi executado e medido (mínimo 20 caracteres).';
        }
        $jaMedido = (float) $c->medicoes()->whereIn('situacao', ['pendente', 'aprovada'])->sum('valor');
        if (round($jaMedido + $valor, 2) > $c->valorAtualizadoTotal()) {
            $m[] = sprintf('As medições somariam R$ %s, acima do valor atualizado do contrato (R$ %s). Formalize o aditivo antes (art. 125).',
                number_format($jaMedido + $valor, 2, ',', '.'), number_format($c->valorAtualizadoTotal(), 2, ',', '.'));
        }
        if ($m) {
            throw new RegraViolada($m);
        }

        return DB::transaction(fn () => Medicao::create([
            'orgao_id' => $c->orgao_id, 'contrato_id' => $c->id, 'numero' => $this->proximoNumero($c),
            'periodo_inicio' => $inicio, 'periodo_fim' => $fim, 'valor' => $valor, 'descricao' => trim($descricao), 'medido_por' => $u->id,
        ]));
    }

    /** Numeração da medição por contrato, com o contrato travado para não repetir número. */
    private function proximoNumero(Contrato $c): int
    {
        Contrato::whereKey($c->id)->lockForUpdate()->first();

        return ((int) Medicao::where('contrato_id', $c->id)->max('numero')) + 1;
    }

    /** O gestor aprova (recebimento definitivo do período) ou recusa com motivo; nunca quem mediu. */
    public function decidirMedicao(Medicao $med, bool $aprovar, ?string $motivo, User $u): void
    {
        if ($med->situacao !== 'pendente') {
            throw new RegraViolada(['Esta medição já foi decidida.']);
        }
        if ($med->medido_por === $u->id) {
            throw new RegraViolada(['Quem mediu não aprova a própria medição (segregação de funções, art. 7º, § 1º, e art. 140).']);
        }
        if (! $aprovar && mb_strlen(trim((string) $motivo)) < 10) {
            throw new RegraViolada(['Informe o motivo da recusa (mínimo 10 caracteres).']);
        }
        $med->update(['situacao' => $aprovar ? 'aprovada' : 'recusada', 'aprovado_por' => $u->id, 'aprovado_em' => now(), 'motivo_recusa' => $aprovar ? null : trim((string) $motivo)]);
    }

    /**
     * Liquidação: confere o direito do credor com a nota fiscal e o atesto. Entra na fila na data da liquidação.
     *
     * @param  array{categoria: string, fonte_recurso: string, nota_fiscal: string, nota_emitida_em: string, valor_bruto: float|string, retencoes?: float|string|null, atesto: string, empenho_id?: int|string|null, pedido_compra?: string|null}  $d
     */
    public function liquidar(Medicao|OrdemFornecimento $origem, array $d, User $u): Liquidacao
    {
        $bruto = round((float) $d['valor_bruto'], 2);
        $retencoes = round((float) ($d['retencoes'] ?? 0), 2);
        $m = [];

        if ($origem instanceof Medicao) {
            $origem->contrato->exigirEficacia('liquidar'); // art. 94
            if ($origem->situacao !== 'aprovada') {
                $m[] = 'Só se liquida medição aprovada pelo gestor.';
            }
            if ($origem->liquidacao()->exists()) {
                $m[] = 'Esta medição já foi liquidada.';
            }
            $limite = (float) $origem->valor;
            $fornecedorId = $origem->contrato->fornecedor_id;
        } else {
            $recebido = $origem->recebido('definitivo');
            if ($recebido <= 0) {
                $m[] = 'Só se liquida ordem com recebimento definitivo (art. 140, I, b, e II, b).';
            }
            $jaLiquidado = (float) Liquidacao::where('ordem_id', $origem->id)->where('situacao', '!=', 'cancelado')->sum('valor_bruto');
            $limite = round($recebido * (float) $origem->valor_unitario - $jaLiquidado, 2);
            $fornecedorId = $origem->ataItem->ata->fornecedor_id;
        }
        $contratoId = $origem instanceof Medicao ? $origem->contrato_id : null;
        $empenho = ! empty($d['empenho_id']) ? Empenho::find((int) $d['empenho_id']) : null;
        if ($empenho) {
            $m = array_merge($m, NotaEmpenho::motivosLiquidacao(['fornecedor_id' => $empenho->fornecedor_id, 'contrato_id' => $empenho->contrato_id, 'ata_id' => $empenho->ata_id, 'fonte_recurso' => $empenho->fonte_recurso,
                'exercicio' => $empenho->exercicio, 'anulado' => $empenho->anulado(), 'saldo' => $empenho->saldo()], $fornecedorId, $contratoId, (string) $d['fonte_recurso'], $bruto, (int) today()->year, $origem instanceof OrdemFornecimento ? $origem->ataItem->ata_id : null));
        } elseif (config('nexplangov.empenho_obrigatorio')) {
            $m[] = 'Escolha a nota de empenho: não há despesa sem prévio empenho (Lei 4.320, art. 60, e Lei 14.133, art. 92, VIII).';
        }
        if ($bruto <= 0 || $bruto > $limite + 0.001) {
            $m[] = sprintf('O valor da nota precisa ser maior que zero e até R$ %s (o que foi medido ou recebido e ainda não liquidado).', number_format(max(0, $limite), 2, ',', '.'));
        }
        if ($retencoes < 0 || $retencoes >= $bruto) {
            $m[] = 'As retenções não podem ser negativas nem iguais ou maiores que o valor bruto.';
        }
        if (! array_key_exists($d['categoria'], OrdemCronologica::CATEGORIAS)) {
            $m[] = 'Escolha a categoria do art. 141 (bens, locações, serviços ou obras).';
        }
        if (mb_strlen(trim($d['fonte_recurso'])) < 3) {
            $m[] = 'Informe a fonte de recursos: cada fonte tem a sua ordem cronológica (art. 141).';
        }
        if ($d['nota_emitida_em'] > today()->toDateString()) {
            $m[] = 'A nota fiscal não pode ter data futura.';
        }
        if (mb_strlen(trim($d['atesto'])) < 20) {
            $m[] = 'Registre o atesto: o objeto foi entregue ou executado conforme o contrato (mínimo 20 caracteres).';
        }
        if ($m) {
            throw new RegraViolada($m);
        }

        $hoje = CarbonImmutable::today();

        return Liquidacao::create([
            'orgao_id' => $origem->orgao_id, 'fornecedor_id' => $fornecedorId,
            'contrato_id' => $origem instanceof Medicao ? $origem->contrato_id : null, 'medicao_id' => $origem instanceof Medicao ? $origem->id : null,
            'ordem_id' => $origem instanceof OrdemFornecimento ? $origem->id : null,
            'empenho_id' => $empenho?->id, 'pedido_compra' => trim((string) ($d['pedido_compra'] ?? '')) ?: ($origem instanceof OrdemFornecimento ? $origem->identificacao() : null),
            'categoria' => $d['categoria'], 'fonte_recurso' => trim($d['fonte_recurso']), 'nota_fiscal' => trim($d['nota_fiscal']), 'nota_emitida_em' => $d['nota_emitida_em'],
            'valor_bruto' => $bruto, 'retencoes' => $retencoes, 'valor_liquido' => round($bruto - $retencoes, 2), 'atesto' => trim($d['atesto']),
            'liquidado_em' => $hoje->toDateString(), 'liquidado_por' => $u->id,
            'vencimento' => $this->regras->vencimento($hoje, (int) Parametros::valor('pagamento_prazo_dias', $origem->orgao_id))->toDateString(),
        ]);
    }

    /**
     * Registra a nota de empenho do credor, ligada ao contrato ou à ata (art. 92, VIII).
     *
     * @param  array{numero: string, tipo: string, valor: float, emitido_em: string, natureza_despesa: string, fonte_recurso: string}  $d
     */
    public function empenhar(Contrato|Ata $origem, array $d, User $u): Empenho
    {
        $d['exercicio'] = (int) substr($d['emitido_em'], 0, 4);
        $saldoContrato = null;
        if ($origem instanceof Contrato) {
            $empenhado = (float) $origem->empenhos()->get()->sum(fn (Empenho $e) => (float) $e->valor - (float) $e->valor_anulado);
            $saldoContrato = round($origem->valorAtualizadoTotal() - $empenhado, 2);
        }
        $m = NotaEmpenho::motivosCadastro($d, today()->toDateString(), $saldoContrato);
        if (Empenho::where('exercicio', $d['exercicio'])->where('numero', trim($d['numero']))->exists()) {
            $m[] = 'Já existe nota de empenho com este número no exercício.';
        }
        if ($m) {
            throw new RegraViolada($m);
        }

        return Empenho::create([
            'orgao_id' => $origem->orgao_id, 'fornecedor_id' => $origem->fornecedor_id,
            'contrato_id' => $origem instanceof Contrato ? $origem->id : null, 'ata_id' => $origem instanceof Ata ? $origem->id : null,
            'numero' => trim($d['numero']), 'exercicio' => $d['exercicio'], 'tipo' => $d['tipo'], 'emitido_em' => $d['emitido_em'],
            'natureza_despesa' => trim($d['natureza_despesa']), 'fonte_recurso' => trim($d['fonte_recurso']), 'valor' => round($d['valor'], 2), 'registrado_por' => $u->id,
        ]);
    }

    /** Anula o saldo não liquidado do empenho, com motivo (Lei 4.320, art. 38). */
    public function anularEmpenho(Empenho $e, string $motivo): void
    {
        $saldo = $e->saldo();
        if ($saldo <= 0) {
            throw new RegraViolada(['Este empenho não tem saldo a anular.']);
        }
        if (mb_strlen(trim($motivo)) < 10) {
            throw new RegraViolada(['Informe o motivo da anulação (mínimo 10 caracteres).']);
        }
        $e->update(['valor_anulado' => round((float) $e->valor_anulado + $saldo, 2), 'motivo_anulacao' => trim($motivo)]);
    }

    /** Créditos em aberto à frente deste na mesma fila. */
    public function aFrente(Liquidacao $l): array
    {
        return Liquidacao::daFila($l->fonte_recurso, $l->categoria)->where('situacao', 'liquidado')->where('id', '!=', $l->id)
            ->where(fn ($q) => $q->where('liquidado_em', '<', $l->liquidado_em)->orWhere(fn ($w) => $w->where('liquidado_em', $l->liquidado_em)->where('id', '<', $l->id)))
            ->emOrdem()->get()->map(fn (Liquidacao $x) => $x->identificacao())->all();
    }

    /** Posição na fila (1 = o próximo a pagar). */
    public function posicao(Liquidacao $l): int
    {
        return count($this->aFrente($l)) + 1;
    }

    /** Justificativa prévia da autoridade para pagar fora da ordem (art. 141, § 1º). */
    public function autorizarQuebra(Liquidacao $l, string $hipotese, string $justificativa, User $u): void
    {
        if ($l->situacao !== 'liquidado') {
            throw new RegraViolada(['Só se justifica a alteração da ordem antes do pagamento.']);
        }
        if ($this->aFrente($l) === []) {
            throw new RegraViolada(['Este crédito já é o próximo da fila: não há ordem a alterar.']);
        }
        $m = $this->regras->validarJustificativa($hipotese, $justificativa);
        if ($m) {
            throw new RegraViolada($m);
        }
        $l->update(['quebra_hipotese' => $hipotese, 'quebra_justificativa' => trim($justificativa), 'quebra_autorizada_por' => $u->id, 'quebra_autorizada_em' => now()]);
    }

    public function pagar(Liquidacao $l, string $data, string $ordemBancaria, User $u): void
    {
        $l->contrato?->exigirEficacia('pagar'); // art. 94
        $m = [];
        if ($l->situacao !== 'liquidado') {
            $m[] = 'Este crédito não está aguardando pagamento.';
        }
        if ($data > today()->toDateString() || $data < $l->liquidado_em->toDateString()) {
            $m[] = 'A data do pagamento deve ficar entre a liquidação e hoje.';
        }
        if (mb_strlen(trim($ordemBancaria)) < 3) {
            $m[] = 'Informe a ordem bancária ou o comprovante do pagamento.';
        }
        $m = array_merge($m, $this->regras->validarPagamento($this->aFrente($l), $l->quebraAutorizada()));
        if ($m) {
            throw new RegraViolada($m);
        }
        $l->update(['situacao' => 'pago', 'pago_em' => $data, 'ordem_bancaria' => trim($ordemBancaria), 'pago_por' => $u->id]);
    }

    public function registrarComunicacao(Liquidacao $l, string $destino, string $data): void
    {
        if (! $l->quebraAutorizada()) {
            throw new RegraViolada(['Só o pagamento fora da ordem exige comunicação ao controle interno e ao tribunal de contas.']);
        }
        if ($data > today()->toDateString()) {
            throw new RegraViolada(['A data da comunicação não pode ser futura.']);
        }
        $l->update([$destino === 'tribunal' ? 'comunicado_tribunal_em' : 'comunicado_controle_em' => $data]);
    }

    public function cancelar(Liquidacao $l, string $motivo): void
    {
        if ($l->situacao !== 'liquidado') {
            throw new RegraViolada(['Só se cancela crédito ainda não pago.']);
        }
        if (mb_strlen(trim($motivo)) < 10) {
            throw new RegraViolada(['Informe o motivo do cancelamento (mínimo 10 caracteres).']);
        }
        $l->update(['situacao' => 'cancelado', 'motivo_cancelamento' => trim($motivo)]);
    }
}
