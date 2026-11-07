<?php

namespace Tests\Feature\Lei14133;

use App\Models\Ata;
use App\Models\Contrato;
use App\Models\Empenho;
use App\Models\Liquidacao;
use App\Models\Medicao;
use App\Models\OrdemFornecimento;
use App\Models\Requisicao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\TestCase;

/** Medição, liquidação e pagamento na ordem cronológica (arts. 140 e 141). */
class PagamentosTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->travelTo('2026-11-03 10:00:00');
    }

    private function contrato(string $cnpj = '99.000.555/0001-55', int $categoria = 8): Contrato
    {
        $p = $this->processo(['categoria' => $categoria], 'contratado');
        $f = $this->fornecedor($cnpj);

        $c = Contrato::create([
            'orgao_id' => $this->orgao->id, 'processo_id' => $p->id, 'fornecedor_id' => $f->id, 'gestor_id' => $this->u['gestor-contrato']->id, 'fiscal_id' => $this->u['fiscal']->id,
            'numero' => (string) random_int(1, 9999), 'ano' => 2026, 'tipo' => 1, 'objeto' => 'Limpeza predial (fictícia).', 'categoria' => $categoria, 'valor_inicial' => 12000, 'valor_global' => 12000,
            'assinatura' => '2026-01-02', 'vigencia_inicio' => '2026-01-02', 'vigencia_fim' => '2026-12-31', 'situacao' => 'vigente',
        ]);

        // Divulgado no PNCP: só assim o contrato tem eficácia para medir e pagar (art. 94).
        $c->forceFill(['numero_controle_pncp' => '11222333000181-2-'.str_pad((string) $c->id, 6, '0', STR_PAD_LEFT).'/2026'])->save();

        return $c;
    }

    private function medirEAprovar(Contrato $c, string $valor = '1.000,00'): Medicao
    {
        $this->actingAs($this->u['fiscal'])->post("/contratos/{$c->id}/medicoes", ['periodo_inicio' => '2026-10-01', 'periodo_fim' => '2026-10-31', 'valor' => $valor, 'descricao' => 'Limpeza diária das 3 sedes em outubro, sem ocorrências.'])->assertSessionHas('sucesso');
        $m = Medicao::latest('id')->firstOrFail();
        $this->actingAs($this->u['gestor-contrato'])->post("/contratos/{$c->id}/medicoes/{$m->id}/decidir", ['acao' => 'aprovar'])->assertSessionHas('sucesso');

        return $m->refresh();
    }

    private function empenho(Contrato $c, string $fonte = '1500 · Recursos livres', string $valor = '6.000,00', string $numero = ''): Empenho
    {
        $this->actingAs($this->u['gestor-contrato'])->post("/contratos/{$c->id}/empenhos", ['numero' => $numero ?: '2026NE'.str_pad((string) (Empenho::count() + 1), 6, '0', STR_PAD_LEFT), 'tipo' => 'global',
            'emitido_em' => '2026-10-01', 'valor' => $valor, 'natureza_despesa' => '3.3.90.39', 'fonte_recurso' => $fonte])->assertSessionHas('sucesso');

        return Empenho::latest('id')->firstOrFail();
    }

    private function liquidar(Medicao $m, string $fonte = '1500 · Recursos livres', string $valor = '1.000,00', ?int $empenho = null): TestResponse
    {
        $empenho ??= (Empenho::where('contrato_id', $m->contrato_id)->where('fonte_recurso', $fonte)->first() ?? $this->empenho($m->contrato, $fonte))->id;

        return $this->actingAs($this->u['gestor-contrato'])->post("/medicoes/{$m->id}/liquidar", [
            'empenho_id' => $empenho, 'categoria' => 'servicos', 'fonte_recurso' => $fonte, 'nota_fiscal' => 'NF '.$m->id, 'nota_emitida_em' => '2026-11-01', 'valor_bruto' => $valor, 'retencoes' => '110,00',
            'atesto' => 'Serviço executado conforme o termo de referência, atestado pelo fiscal.',
        ]);
    }

    private function pagar(Liquidacao $l): TestResponse
    {
        return $this->actingAs($this->u['gestor-contrato'])->post("/pagamentos/{$l->id}/pagar", ['pago_em' => today()->toDateString(), 'ordem_bancaria' => 'OB 2026/'.$l->id]);
    }

    public function test_medicao_aprovada_por_outra_pessoa_e_limitada_ao_valor_do_contrato(): void
    {
        $c = $this->contrato();
        $fiscal = $this->u['fiscal'];
        $this->actingAs($fiscal)->post("/contratos/{$c->id}/medicoes", ['periodo_inicio' => '2025-12-01', 'periodo_fim' => '2025-12-31', 'valor' => '100', 'descricao' => 'Período fora da vigência do contrato.'])->assertSessionHas('motivos');
        $this->actingAs($fiscal)->post("/contratos/{$c->id}/medicoes", ['periodo_inicio' => '2026-10-01', 'periodo_fim' => '2026-10-31', 'valor' => '12.000,01', 'descricao' => 'Medição acima do valor total do contrato.'])->assertSessionHas('motivos', fn ($m) => str_contains(implode(' ', $m), 'art. 125'));
        $this->actingAs($fiscal)->post("/contratos/{$c->id}/medicoes", ['periodo_inicio' => '2026-10-01', 'periodo_fim' => '2026-10-31', 'valor' => '1.000,00', 'descricao' => 'Limpeza diária das 3 sedes em outubro.'])->assertSessionHas('sucesso');
        $m = Medicao::firstOrFail();
        $this->assertSame(1, $m->numero);

        // Quem mediu não aprova; liquidar só depois de aprovada.
        $this->actingAs($this->u['gestor-contrato'])->post("/contratos/{$c->id}/medicoes/{$m->id}/decidir", ['acao' => 'aprovar']); // gestor aprova (outra pessoa)
        $outra = $this->contrato('99.000.666/0001-07');
        $this->actingAs($fiscal)->post("/contratos/{$outra->id}/medicoes", ['periodo_inicio' => '2026-10-01', 'periodo_fim' => '2026-10-31', 'valor' => '500', 'descricao' => 'Medição do segundo contrato fictício.'])->assertSessionHas('sucesso');
        $m2 = Medicao::latest('id')->firstOrFail();
        $this->actingAs($fiscal)->post("/contratos/{$outra->id}/medicoes/{$m2->id}/decidir", ['acao' => 'aprovar'])->assertSessionHas('motivos', fn ($x) => str_contains(implode(' ', $x), 'segregação'));
        $this->liquidar($m2, valor: '500')->assertSessionHas('motivos');

        $this->expectException(LogicException::class);
        $m->delete();
    }

    public function test_paga_na_ordem_por_fonte_e_categoria(): void
    {
        $c = $this->contrato();
        $a = $this->medirEAprovar($c);
        $this->liquidar($a)->assertSessionHas('sucesso');
        $l1 = Liquidacao::latest('id')->firstOrFail();
        $this->assertEquals(890.0, (float) $l1->valor_liquido);
        $this->assertSame('2026-12-03', $l1->vencimento->toDateString());
        $this->liquidar($a)->assertSessionHas('motivos'); // a mesma medição não se liquida duas vezes

        $this->travelTo('2026-11-04 10:00:00');
        $b = $this->medirEAprovar($this->contrato('99.000.666/0001-07'));
        $this->liquidar($b)->assertSessionHas('sucesso');
        $l2 = Liquidacao::latest('id')->firstOrFail();

        // Outra fonte é outra fila: pode pagar sem esperar.
        $d = $this->medirEAprovar($this->contrato('99.000.777/0001-05'));
        $this->liquidar($d, '1700 · Saúde')->assertSessionHas('sucesso');
        $l3 = Liquidacao::latest('id')->firstOrFail();

        $this->pagar($l2)->assertSessionHas('motivos', fn ($m) => str_contains(implode(' ', $m), 'art. 141, § 1º'));
        $this->pagar($l3)->assertSessionHas('sucesso');
        $this->pagar($l1)->assertSessionHas('sucesso');
        $this->pagar($l2)->assertSessionHas('sucesso');
        $this->assertSame('pago', $l2->refresh()->situacao);

        $this->actingAs($this->u['auditor'])->get('/pagamentos')->assertOk()->assertSee('Últimos pagamentos');
    }

    public function test_pagar_fora_da_ordem_exige_justificativa_da_autoridade_e_comunicacao(): void
    {
        $this->liquidar($this->medirEAprovar($this->contrato()))->assertSessionHas('sucesso');
        $this->liquidar($this->medirEAprovar($this->contrato('99.000.666/0001-07')))->assertSessionHas('sucesso');
        $l2 = Liquidacao::latest('id')->firstOrFail();

        $this->actingAs($this->u['gestor-contrato'])->post("/pagamentos/{$l2->id}/quebra", ['hipotese' => 'II', 'justificativa' => str_repeat('x', 60)])->assertForbidden();
        $this->actingAs($this->u['autoridade'])->post("/pagamentos/{$l2->id}/quebra", ['hipotese' => 'II', 'justificativa' => 'curta'])->assertSessionHas('motivos');
        $this->actingAs($this->u['autoridade'])->post("/pagamentos/{$l2->id}/quebra", ['hipotese' => 'II', 'justificativa' => 'Microempresa fornecedora da limpeza das escolas, com risco de parar o serviço.'])->assertSessionHas('sucesso');
        $this->pagar($l2)->assertSessionHas('sucesso');
        $this->assertTrue($l2->refresh()->comunicacaoPendente());

        $this->actingAs($this->u['gestor-contrato'])->get('/pagamentos')->assertSee('Pagamento fora da ordem sem comunicação');
        $this->actingAs($this->u['gestor-contrato'])->post("/pagamentos/{$l2->id}/comunicacao", ['destino' => 'controle', 'data' => '2026-11-03'])->assertSessionHas('sucesso');
        $this->actingAs($this->u['gestor-contrato'])->post("/pagamentos/{$l2->id}/comunicacao", ['destino' => 'tribunal', 'data' => '2026-11-03'])->assertSessionHas('sucesso');
        $this->assertFalse($l2->refresh()->comunicacaoPendente());

        // Divulgação mensal, sem login, com a justificativa.
        Auth::logout();
        $this->get("/transparencia/{$this->orgao->id}/pagamentos?mes=2026-11")->assertOk()->assertSee('Pago fora da ordem')->assertSee('Microempresa fornecedora');
    }

    public function test_ordem_de_fornecimento_recebida_e_liquidada_ate_o_valor_recebido(): void
    {
        $p = $this->processo(['srp' => true], 'homologado');
        $f = $this->fornecedor('99.000.333/0001-88');
        $p->itens()->create(['orgao_id' => $p->orgao_id, 'numero' => 1, 'material_servico' => 'M', 'descricao' => 'Papel A4 (fictício)', 'unidade_medida' => 'UN', 'quantidade' => 100, 'tipo_beneficio' => 4, 'situacao' => 'homologado', 'fornecedor_vencedor_id' => $f->id, 'valor_unitario_homologado' => 10.0]);
        $g = $this->u['gestor-contrato'];
        $this->actingAs($g)->post('/atas', ['processo_id' => $p->id, 'assinatura' => '2026-11-03', 'vigencia_inicio' => '2026-11-03', 'vigencia_fim' => '2027-11-02'])->assertSessionHas('sucesso');
        $ai = Ata::firstOrFail()->itens()->first();
        $this->actingAs($this->u['requisitante'])->post('/requisicoes', ['ata_item_id' => $ai->id, 'quantidade' => '30', 'unidade_id' => $this->unidade->id, 'justificativa' => 'Reposição do estoque da secretaria.'])->assertSessionHas('sucesso');
        $r = Requisicao::firstOrFail();
        $this->actingAs($g)->post("/requisicoes/{$r->id}/decidir", ['acao' => 'aprovar']);
        $this->actingAs($g)->post("/requisicoes/{$r->id}/ordem", ['prazo_entrega' => '2026-11-13'])->assertSessionHas('sucesso');
        $o = OrdemFornecimento::firstOrFail();

        $this->actingAs($g)->post("/atas/{$ai->ata_id}/empenhos", ['numero' => '2026NE000900', 'tipo' => 'estimativo', 'emitido_em' => '2026-11-03', 'valor' => '1.000,00', 'natureza_despesa' => '3.3.90.30', 'fonte_recurso' => '1500 · Recursos livres'])->assertSessionHas('sucesso');
        $dados = ['empenho_id' => Empenho::firstOrFail()->id, 'pedido_compra' => '', 'categoria' => 'fornecimento', 'fonte_recurso' => '1500 · Recursos livres', 'nota_fiscal' => 'NF 77', 'nota_emitida_em' => '2026-11-03', 'valor_bruto' => '200', 'atesto' => 'Papel entregue e conferido no almoxarifado central.'];
        $this->actingAs($g)->post("/ordens/{$o->id}/liquidar", $dados)->assertSessionHas('motivos', fn ($m) => str_contains(implode(' ', $m), 'recebimento definitivo'));

        $rec = ['quantidade' => '20', 'documento' => 'NF 77', 'data' => '2026-11-03'];
        $this->actingAs($this->u['fiscal'])->post("/ordens/{$o->id}/recebimentos", $rec + ['tipo' => 'provisorio'])->assertSessionHas('sucesso');
        $this->actingAs($this->usuario('gestor-contrato', $this->orgao))->post("/ordens/{$o->id}/recebimentos", $rec + ['tipo' => 'definitivo'])->assertSessionHas('sucesso');

        $this->actingAs($g)->get('/pagamentos')->assertSee('Ordem '.$o->identificacao());
        $this->actingAs($g)->post("/ordens/{$o->id}/liquidar", ['valor_bruto' => '200,01'] + $dados)->assertSessionHas('motivos');
        $this->actingAs($g)->post("/ordens/{$o->id}/liquidar", $dados)->assertSessionHas('sucesso');
        $this->assertSame($f->id, Liquidacao::firstOrFail()->fornecedor_id);
        $this->assertSame($o->identificacao(), Liquidacao::firstOrFail()->pedido_compra, 'a ordem de fornecimento é o pedido de compra');
        $this->assertEquals(800.0, Empenho::firstOrFail()->saldo());
    }

    public function test_liquidacao_exige_empenho_do_credor_com_saldo(): void
    {
        $c = $this->contrato();
        $g = $this->u['gestor-contrato'];
        $this->actingAs($g)->post("/contratos/{$c->id}/empenhos", ['numero' => 'NE', 'tipo' => 'global', 'emitido_em' => '2026-12-01', 'valor' => '13.000,00', 'natureza_despesa' => 'x', 'fonte_recurso' => '1500'])
            ->assertSessionHas('motivos', fn ($m) => count($m) >= 4 && str_contains(implode(' ', $m), 'art. 125'));
        $e = $this->empenho($c, valor: '1.500,00', numero: '2026NE000777');
        $this->actingAs($g)->post("/contratos/{$c->id}/empenhos", ['numero' => '2026NE000777', 'tipo' => 'global', 'emitido_em' => '2026-10-02', 'valor' => '10', 'natureza_despesa' => '3.3.90.39', 'fonte_recurso' => '1500'])
            ->assertSessionHas('motivos', fn ($m) => str_contains(implode(' ', $m), 'Já existe'));

        $m = $this->medirEAprovar($c);
        $this->actingAs($g)->post("/medicoes/{$m->id}/liquidar", ['empenho_id' => '', 'categoria' => 'servicos', 'fonte_recurso' => '1500 · Recursos livres', 'nota_fiscal' => 'NF 1', 'nota_emitida_em' => '2026-11-01', 'valor_bruto' => '1.000,00', 'atesto' => 'Serviço executado conforme o termo de referência.'])
            ->assertSessionHas('motivos', fn ($x) => str_contains(implode(' ', $x), 'art. 60'));
        $outro = $this->empenho($this->contrato('99.000.666/0001-07'));
        $this->liquidar($m, empenho: $outro->id)->assertSessionHas('motivos', fn ($x) => str_contains(implode(' ', $x), 'outro credor'));
        $this->liquidar($m, '1700 · Saúde', empenho: $e->id)->assertSessionHas('motivos', fn ($x) => str_contains(implode(' ', $x), 'fonte da liquidação'));
        $this->liquidar($m, empenho: $e->id)->assertSessionHas('sucesso');
        $this->assertEquals(500.0, $e->saldo());

        $m2 = $this->medirEAprovar($c);
        $this->liquidar($m2, empenho: $e->id)->assertSessionHas('motivos', fn ($x) => str_contains(implode(' ', $x), 'saldo do empenho'));
        $this->actingAs($g)->get("/contratos/{$c->id}")->assertSee('2026NE000777/2026')->assertSee('R$ 500,00');

        $this->actingAs($g)->post("/empenhos/{$e->id}/anular", ['motivo' => 'Serviço encerrado antes do previsto.'])->assertSessionHas('sucesso');
        $this->assertEquals(0.0, $e->fresh()->saldo());
        $this->assertFalse($e->fresh()->anulado(), 'o empenho com parte liquidada não fica anulado por inteiro');
    }

    public function test_atraso_acima_de_dois_meses_aparece_na_fila(): void
    {
        $this->liquidar($this->medirEAprovar($this->contrato()))->assertSessionHas('sucesso');
        $this->travelTo('2027-02-05 10:00:00');
        $this->actingAs($this->u['gestor-contrato'])->get('/pagamentos')->assertOk()->assertSee('art. 137, § 2º, IV');
        $this->actingAs($this->u['gestor-contrato'])->get('/contratos/'.Contrato::first()->id)->assertOk()->assertSee('Medições e pagamentos');
    }
}
