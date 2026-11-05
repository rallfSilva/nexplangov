<?php

namespace Tests\Feature\Lei14133;

use App\Licitacao\Alertas\Detector;
use App\Models\Contrato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Contrato só produz efeitos depois de divulgado no PNCP (art. 94). */
class EficaciaContratoTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    private function contrato(int $modalidade = 6, string $assinatura = '2026-03-02', string $numero = '5'): Contrato
    {
        $p = $this->processo(['modalidade' => $modalidade, 'amparo_legal' => $modalidade === 8 ? 19 : 1], 'contratado');

        return Contrato::create([
            'orgao_id' => $this->orgao->id, 'processo_id' => $p->id, 'fornecedor_id' => $this->fornecedor('99.000.333/0001-'.str_pad($numero, 2, '0', STR_PAD_LEFT))->id, 'gestor_id' => $this->u['gestor-contrato']->id, 'fiscal_id' => $this->u['fiscal']->id,
            'numero' => $numero, 'ano' => 2026, 'tipo' => 1, 'objeto' => 'Limpeza predial (fictícia).', 'categoria' => 8, 'valor_inicial' => 12000, 'valor_global' => 12000,
            'assinatura' => $assinatura, 'vigencia_inicio' => $assinatura, 'vigencia_fim' => '2026-12-31', 'situacao' => 'vigente',
        ]);
    }

    public function test_contrato_sem_publicacao_no_pncp_nao_recebe_medicao(): void
    {
        $c = $this->contrato();

        $this->actingAs($this->u['fiscal'])->post("/contratos/{$c->id}/medicoes", ['periodo_inicio' => '2026-10-01', 'periodo_fim' => '2026-10-31', 'valor' => '1.000,00', 'descricao' => 'Limpeza diária das 3 sedes em outubro.'])
            ->assertSessionHas('motivos', fn ($m) => str_contains(implode(' ', $m), 'art. 94'));
        $this->assertSame(0, $c->medicoes()->count());

        $c->forceFill(['numero_controle_pncp' => '11222333000181-2-000005/2026'])->save();

        $this->actingAs($this->u['fiscal'])->post("/contratos/{$c->id}/medicoes", ['periodo_inicio' => '2026-10-01', 'periodo_fim' => '2026-10-31', 'valor' => '1.000,00', 'descricao' => 'Limpeza diária das 3 sedes em outubro.'])
            ->assertSessionHas('sucesso');
    }

    public function test_tela_do_contrato_mostra_o_prazo_conforme_a_forma_de_contratacao(): void
    {
        $licitacao = $this->contrato(6, today()->toDateString());

        $this->actingAs($this->u['gestor-contrato'])->get("/contratos/{$licitacao->id}")->assertOk()->assertSee('art. 94, I')->assertSee('Publique no PNCP até');
        $this->assertSame('art. 94, II', $this->contrato(8, today()->toDateString(), '6')->eficacia()['base']);
    }

    public function test_alerta_avisa_o_prazo_e_vira_urgente_quando_vence(): void
    {
        $c = $this->contrato(8, '2026-03-02'); // contratação direta: 10 dias úteis, até 16/03/2026

        $antes = collect(app(Detector::class)->detectar(today()->setDate(2026, 3, 5)))->firstWhere('chave', "pncp94:{$c->id}:aberto");
        $this->assertNotNull($antes);
        $this->assertFalse($antes['urgente']);
        $this->assertContains($this->u['gestor-contrato']->id, $antes['users']);

        $vencido = collect(app(Detector::class)->detectar(today()->setDate(2026, 3, 20)))->firstWhere('chave', "pncp94:{$c->id}:vencido");
        $this->assertTrue($vencido['urgente']);
        $this->assertStringContainsString('não tem eficácia', $vencido['texto']);

        $c->forceFill(['numero_controle_pncp' => '11222333000181-2-000005/2026'])->save();
        $this->assertNull(collect(app(Detector::class)->detectar(today()->setDate(2026, 3, 20)))->first(fn ($a) => str_starts_with($a['chave'], 'pncp94:')));
    }
}
