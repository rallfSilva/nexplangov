<?php

namespace Tests\Feature\Lei14133;

use App\Licitacao\Alertas\Detector;
use App\Licitacao\Alertas\EnviadorDeAlertas;
use App\Models\AlertaEnviado;
use App\Models\Ata;
use App\Models\AtaItem;
use App\Models\Contrato;
use App\Models\OrdemFornecimento;
use App\Models\Recebimento;
use App\Models\Requisicao;
use App\Notifications\AlertaDePrazos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Tests\TestCase;

/** Requisição, ordem de fornecimento, recebimento (art. 140) e alertas de prazo. */
class OrdensAlertasTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    private function ataItem(float $qtd = 100): AtaItem
    {
        $p = $this->processo(['srp' => true], 'homologado');
        $f = $this->fornecedor('99.000.333/0001-88');
        $p->itens()->create(['orgao_id' => $p->orgao_id, 'numero' => 1, 'material_servico' => 'M', 'descricao' => 'Papel A4 75g', 'unidade_medida' => 'UN', 'quantidade' => $qtd, 'tipo_beneficio' => 4, 'situacao' => 'homologado', 'fornecedor_vencedor_id' => $f->id, 'valor_unitario_homologado' => 10.0]);
        $this->actingAs($this->u['gestor-contrato'])->post('/atas', ['processo_id' => $p->id, 'assinatura' => today()->toDateString(), 'vigencia_inicio' => today()->toDateString(), 'vigencia_fim' => today()->addYear()->subDay()->toDateString()])->assertSessionHas('sucesso');

        return Ata::first()->itens()->first();
    }

    private function requisitar(AtaItem $ai, string $qtd = '30'): Requisicao
    {
        $this->actingAs($this->u['requisitante'])->post('/requisicoes', ['ata_item_id' => $ai->id, 'quantidade' => $qtd, 'unidade_id' => $this->unidade->id, 'justificativa' => 'Reposição do estoque da secretaria.'])->assertSessionHas('sucesso');

        return Requisicao::latest('id')->first();
    }

    private function ordem(AtaItem $ai, string $qtd = '30'): OrdemFornecimento
    {
        $r = $this->requisitar($ai, $qtd);
        $this->actingAs($this->u['gestor-contrato'])->post("/requisicoes/{$r->id}/decidir", ['acao' => 'aprovar'])->assertSessionHas('sucesso');
        $this->actingAs($this->u['gestor-contrato'])->post("/requisicoes/{$r->id}/ordem", ['prazo_entrega' => today()->addDays(10)->toDateString()])->assertSessionHas('sucesso');

        return OrdemFornecimento::first();
    }

    public function test_requisicao_nao_passa_do_saldo_e_exige_justificativa(): void
    {
        $ai = $this->ataItem(100);
        $this->actingAs($this->u['requisitante'])->post('/requisicoes', ['ata_item_id' => $ai->id, 'quantidade' => '101', 'justificativa' => 'Reposição do estoque.'])->assertSessionHas('motivos');
        $this->actingAs($this->u['requisitante'])->post('/requisicoes', ['ata_item_id' => $ai->id, 'quantidade' => '5', 'justificativa' => 'curta'])->assertSessionHas('motivos');
        $this->assertSame(0, Requisicao::count());
    }

    public function test_quem_requisita_nao_aprova_a_propria_requisicao(): void
    {
        $r = $this->requisitar($this->ataItem());
        $r->forceFill(['requisitante_id' => $this->u['gestor-contrato']->id])->save();

        $this->actingAs($this->u['gestor-contrato'])->post("/requisicoes/{$r->id}/decidir", ['acao' => 'aprovar'])->assertSessionHas('motivos');
        $this->assertSame('solicitada', $r->refresh()->situacao);
    }

    public function test_requisitante_nao_aprova_nem_emite(): void
    {
        $r = $this->requisitar($this->ataItem());
        $this->actingAs($this->u['requisitante'])->post("/requisicoes/{$r->id}/decidir", ['acao' => 'aprovar'])->assertForbidden();
        $this->actingAs($this->u['requisitante'])->post("/requisicoes/{$r->id}/ordem", ['prazo_entrega' => today()->addDay()->toDateString()])->assertForbidden();
    }

    public function test_ordem_so_sai_de_requisicao_aprovada_e_reserva_o_saldo(): void
    {
        $ai = $this->ataItem(100);
        $r = $this->requisitar($ai, '30');

        $this->actingAs($this->u['gestor-contrato'])->post("/requisicoes/{$r->id}/ordem", ['prazo_entrega' => today()->addDays(5)->toDateString()])->assertSessionHas('motivos');
        $this->assertSame(0, OrdemFornecimento::count());

        $o = $this->ordem($ai);
        $this->assertEquals(70.0, $ai->refresh()->saldo());
        $this->assertEquals(300.0, (float) $o->valor_total);
        $this->assertSame('solicitada', $r->refresh()->situacao); // a primeira segue sem decisão
        $this->assertSame('atendida', Requisicao::latest('id')->first()->situacao);
        $this->assertSame(now()->year.'', (string) $o->ano);
    }

    public function test_recebimento_definitivo_exige_provisorio_e_pessoa_diferente(): void
    {
        $o = $this->ordem($this->ataItem());
        $fiscal = $this->u['fiscal'];
        $gestor = $this->u['gestor-contrato']; // emitiu a ordem

        $dados = fn ($tipo, $qtd = '30') => ['tipo' => $tipo, 'quantidade' => $qtd, 'documento' => 'NF 123', 'data' => today()->toDateString()];

        $this->actingAs($fiscal)->post("/ordens/{$o->id}/recebimentos", $dados('definitivo'))->assertSessionHas('motivos'); // sem provisório
        $this->actingAs($fiscal)->post("/ordens/{$o->id}/recebimentos", $dados('provisorio', '31'))->assertSessionHas('motivos'); // passa da ordem
        $this->actingAs($fiscal)->post("/ordens/{$o->id}/recebimentos", $dados('provisorio'))->assertSessionHas('sucesso');
        $this->actingAs($fiscal)->post("/ordens/{$o->id}/recebimentos", $dados('definitivo'))->assertSessionHas('motivos'); // mesma pessoa do provisório
        $this->actingAs($gestor)->post("/ordens/{$o->id}/recebimentos", $dados('definitivo'))->assertSessionHas('motivos'); // quem emitiu

        $this->assertSame('parcial', $o->refresh()->situacao);
    }

    public function test_entrega_completa_encerra_a_ordem(): void
    {
        $o = $this->ordem($this->ataItem());
        $dados = ['quantidade' => '30', 'documento' => 'NF 9', 'data' => today()->toDateString()];
        $outro = $this->usuario('gestor-contrato', $this->orgao);

        $this->actingAs($this->u['fiscal'])->post("/ordens/{$o->id}/recebimentos", $dados + ['tipo' => 'provisorio'])->assertSessionHas('sucesso');
        $this->actingAs($outro)->post("/ordens/{$o->id}/recebimentos", $dados + ['tipo' => 'definitivo'])->assertSessionHas('sucesso');

        $this->assertSame('entregue', $o->refresh()->situacao);
        $this->actingAs($outro)->post("/ordens/{$o->id}/recebimentos", $dados + ['tipo' => 'provisorio'])->assertSessionHas('motivos');
    }

    public function test_recebimento_e_imutavel(): void
    {
        $o = $this->ordem($this->ataItem());
        $this->actingAs($this->u['fiscal'])->post("/ordens/{$o->id}/recebimentos", ['tipo' => 'provisorio', 'quantidade' => '5', 'documento' => 'NF 1', 'data' => today()->toDateString()]);

        $this->expectException(LogicException::class);
        Recebimento::first()->delete();
    }

    public function test_cancelar_ordem_devolve_o_saldo_por_estorno_sem_apagar_nada(): void
    {
        $ai = $this->ataItem(100);
        $o = $this->ordem($ai);
        $this->assertEquals(70.0, $ai->refresh()->saldo());

        $this->actingAs($this->u['gestor-contrato'])->post("/ordens/{$o->id}/cancelar", ['motivo' => 'x'])->assertSessionHas('motivos');
        $this->actingAs($this->u['gestor-contrato'])->post("/ordens/{$o->id}/cancelar", ['motivo' => 'Pedido duplicado pela unidade.'])->assertSessionHas('sucesso');

        $this->assertEquals(100.0, $ai->refresh()->saldo());
        $this->assertSame('cancelada', $o->refresh()->situacao);
        $this->assertSame('cancelada', Requisicao::first()->situacao);
        $this->assertSame(2, $ai->consumos()->count()); // consumo + estorno
    }

    public function test_ordem_com_recebimento_nao_pode_ser_cancelada(): void
    {
        $o = $this->ordem($this->ataItem());
        $this->actingAs($this->u['fiscal'])->post("/ordens/{$o->id}/recebimentos", ['tipo' => 'provisorio', 'quantidade' => '5', 'documento' => 'NF 1', 'data' => today()->toDateString()]);

        $this->actingAs($this->u['gestor-contrato'])->post("/ordens/{$o->id}/cancelar", ['motivo' => 'Tentativa tardia de cancelar.'])->assertSessionHas('motivos');
    }

    public function test_requisitante_so_ve_as_proprias_requisicoes(): void
    {
        $this->requisitar($this->ataItem());
        $outro = $this->usuario('requisitante', $this->orgao);

        $this->actingAs($outro)->get('/requisicoes')->assertOk()->assertDontSee('Reposição do estoque da secretaria.');
        $this->actingAs($this->u['gestor-contrato'])->get('/requisicoes')->assertOk()->assertSee('Reposição do estoque da secretaria.');
    }

    public function test_telas_de_ordens_abrem(): void
    {
        $o = $this->ordem($this->ataItem());
        $this->actingAs($this->u['gestor-contrato'])->get('/ordens')->assertOk()->assertSee($o->identificacao());
        $this->actingAs($this->u['gestor-contrato'])->get("/ordens/{$o->id}")->assertOk()->assertSee('Registrar recebimento');
    }

    // ----- Alertas -----

    public function test_marco_de_alerta_e_o_menor_alcancado(): void
    {
        $m = [120, 90, 60, 30, 15, 7];
        $this->assertNull(Detector::marcoAtingido(121, $m));
        $this->assertSame(120, Detector::marcoAtingido(100, $m));
        $this->assertSame(30, Detector::marcoAtingido(20, $m));
        $this->assertSame(7, Detector::marcoAtingido(7, $m));
        $this->assertSame(0, Detector::marcoAtingido(-1, $m));
    }

    private function contratoVencendoEm(int $dias): Contrato
    {
        $p = $this->processo([], 'contratado');
        $f = $this->fornecedor('99.000.555/0001-55');

        return Contrato::create([
            'orgao_id' => $this->orgao->id, 'processo_id' => $p->id, 'fornecedor_id' => $f->id, 'gestor_id' => $this->u['gestor-contrato']->id, 'fiscal_id' => $this->u['fiscal']->id,
            'numero' => '1', 'ano' => 2026, 'tipo' => 1, 'objeto' => 'Fornecimento de papel.', 'categoria' => 2, 'valor_inicial' => 1000, 'valor_global' => 1000,
            'assinatura' => today()->subYear(), 'vigencia_inicio' => today()->subYear(), 'vigencia_fim' => today()->addDays($dias), 'situacao' => 'vigente',
        ]);
    }

    public function test_alerta_de_contrato_vai_para_gestor_e_fiscal_e_nao_repete(): void
    {
        Notification::fake();
        $this->contratoVencendoEm(25);
        $enviador = app(EnviadorDeAlertas::class);

        $this->assertSame(2, $enviador->enviar());
        Notification::assertSentTo($this->u['gestor-contrato'], AlertaDePrazos::class);
        Notification::assertSentTo($this->u['fiscal'], AlertaDePrazos::class);
        Notification::assertNotSentTo($this->u['requisitante'], AlertaDePrazos::class);

        $this->assertSame(0, $enviador->enviar()); // mesmo marco, nada novo
        $this->assertSame(2, AlertaEnviado::count());
    }

    public function test_alerta_volta_quando_chega_a_um_marco_mais_proximo(): void
    {
        Notification::fake();
        $c = $this->contratoVencendoEm(25); // marco 30
        $enviador = app(EnviadorDeAlertas::class);
        $enviador->enviar();

        $c->forceFill(['vigencia_fim' => today()->addDays(10)])->save(); // marco 15
        $this->assertSame(2, $enviador->enviar());
    }

    public function test_contrato_longe_do_fim_nao_gera_alerta(): void
    {
        $this->contratoVencendoEm(300);
        $this->assertSame([], app(Detector::class)->detectar());
    }

    public function test_ordem_atrasada_alerta_quem_gerencia_contratos(): void
    {
        $o = $this->ordem($this->ataItem());
        $o->forceFill(['prazo_entrega' => today()->subDays(2)])->save();

        $alertas = app(Detector::class)->detectar();
        $a = collect($alertas)->firstWhere('chave', "ordem:{$o->id}:atrasada");
        $this->assertNotNull($a);
        $this->assertContains($this->u['gestor-contrato']->id, $a['users']);
        $this->assertTrue($a['urgente']);
    }

    public function test_alerta_nao_leva_cpf_e_aparece_no_painel(): void
    {
        $this->contratoVencendoEm(5);
        $this->actingAs($this->u['gestor-contrato'])->get('/painel')->assertOk()->assertSee('Prazos que pedem atenção')->assertSee('vence em 5 dia(s)');

        $n = (new AlertaDePrazos(app(Detector::class)->detectar()))->toMail($this->u['gestor-contrato']);
        $this->assertDoesNotMatchRegularExpression('/\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/', implode(' ', $n->introLines));
    }

    public function test_comando_agendado_existe_e_roda(): void
    {
        Notification::fake();
        $this->contratoVencendoEm(3);
        $this->artisan('nexplangov:alertas')->expectsOutputToContain('resumo(s) enviado(s)')->assertSuccessful();
        Notification::assertSentTo($this->u['gestor-contrato'], AlertaDePrazos::class);
    }
}
