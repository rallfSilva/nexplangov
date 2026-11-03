<?php

namespace Tests\Feature\Lei14133;

use App\Models\Ata;
use App\Models\AtaAdesao;
use App\Models\AtaConsumo;
use App\Models\Contrato;
use App\Models\Orgao;
use App\Models\Processo;
use App\Models\Reequilibrio;
use App\Models\Rescisao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\TestCase;

/** Atas de registro de preços (arts. 82 a 86), reequilíbrio (art. 124, II, d) e extinção (arts. 137 a 139). */
class AtasContratosTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    /** Processo SRP homologado com dois itens de fornecedores diferentes. */
    private function processoSrp(): Processo
    {
        $p = $this->processo(['srp' => true], 'homologado');
        $f1 = $this->fornecedor('99.000.333/0001-88');
        $f2 = $this->fornecedor('99.000.444/0001-44');

        foreach ([[$f1, 1000, 5.0], [$f2, 200, 12.5]] as $i => [$f, $q, $v]) {
            $p->itens()->create([
                'orgao_id' => $p->orgao_id, 'numero' => $i + 1, 'material_servico' => 'M', 'descricao' => "Item {$i} de teste", 'unidade_medida' => 'UN', 'quantidade' => $q,
                'tipo_beneficio' => 4, 'situacao' => 'homologado', 'fornecedor_vencedor_id' => $f->id, 'valor_unitario_homologado' => $v,
            ]);
        }

        return $p;
    }

    private function gerarAtas(?Processo $p = null): Processo
    {
        $p ??= $this->processoSrp();
        $this->actingAs($this->u['gestor-contrato'])->post('/atas', ['processo_id' => $p->id, 'assinatura' => today()->toDateString(), 'vigencia_inicio' => today()->toDateString(), 'vigencia_fim' => today()->addYear()->subDay()->toDateString()])->assertSessionHas('sucesso');

        return $p;
    }

    public function test_gera_uma_ata_por_fornecedor_vencedor_com_os_itens_dele(): void
    {
        $this->gerarAtas();

        $this->assertSame(2, Ata::count());
        $this->assertSame(1, Ata::first()->itens()->count());
        $this->assertEquals(1000.0, (float) Ata::first()->itens()->first()->quantidade_registrada);
        $this->assertSame(now()->year.'-001', Ata::orderBy('id')->first()->ano.'-'.Ata::orderBy('id')->first()->numero);
    }

    public function test_nao_gera_ata_de_processo_fora_do_srp_nao_homologado_ou_duplicada(): void
    {
        $g = $this->u['gestor-contrato'];
        $dados = fn ($p) => ['processo_id' => $p->id, 'assinatura' => today()->toDateString(), 'vigencia_inicio' => today()->toDateString(), 'vigencia_fim' => today()->addYear()->subDay()->toDateString()];

        $comum = $this->processo([], 'homologado');
        $this->actingAs($g)->post('/atas', $dados($comum))->assertSessionHas('motivos');

        $p = $this->processoSrp();
        $p->forceFill(['situacao' => 'em_julgamento'])->save();
        $this->actingAs($g)->post('/atas', $dados($p))->assertSessionHas('motivos');

        $p->forceFill(['situacao' => 'homologado'])->save();
        $this->actingAs($g)->post('/atas', array_merge($dados($p), ['vigencia_fim' => today()->addYears(2)->toDateString()]))->assertSessionHas('motivos'); // mais de 1 ano
        $this->actingAs($g)->post('/atas', $dados($p))->assertSessionHas('sucesso');
        $this->actingAs($g)->post('/atas', $dados($p))->assertSessionHas('motivos'); // duplicada
    }

    public function test_saldo_diminui_com_o_consumo_e_nao_fica_negativo(): void
    {
        $this->gerarAtas();
        $ata = Ata::first();
        $ai = $ata->itens()->first();
        $g = $this->u['gestor-contrato'];

        $this->actingAs($g)->post("/atas/{$ata->id}/itens/{$ai->id}/consumos", ['quantidade' => '400', 'referencia' => 'Empenho 2026NE0001'])->assertSessionHas('sucesso');
        $this->assertEquals(600.0, $ai->refresh()->saldo());

        $this->actingAs($g)->post("/atas/{$ata->id}/itens/{$ai->id}/consumos", ['quantidade' => '600,0001', 'referencia' => 'Empenho 2026NE0002'])->assertSessionHas('motivos');
        $this->actingAs($g)->post("/atas/{$ata->id}/itens/{$ai->id}/consumos", ['quantidade' => '600', 'referencia' => 'Empenho 2026NE0003'])->assertSessionHas('sucesso');
        $this->assertEquals(0.0, $ai->refresh()->saldo());
    }

    public function test_consumo_e_imutavel(): void
    {
        $this->gerarAtas();
        $ata = Ata::first();
        $this->actingAs($this->u['gestor-contrato'])->post("/atas/{$ata->id}/itens/".$ata->itens()->first()->id.'/consumos', ['quantidade' => '1', 'referencia' => 'Empenho 1']);

        $this->expectException(LogicException::class);
        AtaConsumo::first()->delete();
    }

    public function test_ata_vencida_ou_cancelada_nao_aceita_consumo(): void
    {
        $this->gerarAtas();
        $ata = Ata::first();
        $ai = $ata->itens()->first();
        $g = $this->u['gestor-contrato'];

        $ata->update(['vigencia_fim' => today()->subDay()]);
        $this->actingAs($g)->post("/atas/{$ata->id}/itens/{$ai->id}/consumos", ['quantidade' => '1', 'referencia' => 'Empenho 1'])->assertSessionHas('motivos');

        $ata->update(['vigencia_fim' => today()->addMonth()]);
        $this->actingAs($g)->post("/atas/{$ata->id}/cancelar", ['motivo' => 'curto'])->assertSessionHas('motivos');
        $this->actingAs($g)->post("/atas/{$ata->id}/cancelar", ['motivo' => 'Fornecedor deixou de entregar.'])->assertSessionHas('sucesso');
        $this->actingAs($g)->post("/atas/{$ata->id}/itens/{$ai->id}/consumos", ['quantidade' => '1', 'referencia' => 'Empenho 1'])->assertSessionHas('motivos');
    }

    /** CNPJ fictício com dígitos verificadores válidos, de base 99.xxx.xxx. */
    private function cnpj(int $n): string
    {
        $b = '99'.str_pad((string) $n, 6, '0', STR_PAD_LEFT).'0001';
        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $pesos) {
            $soma = 0;
            foreach (str_split($b) as $i => $d) {
                $soma += (int) $d * $pesos[$i];
            }
            $r = $soma % 11;
            $b .= $r < 2 ? '0' : (string) (11 - $r);
        }

        return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $b);
    }

    private function adesao(Ata $ata, string $qtd, ?string $cnpj = null): TestResponse
    {
        return $this->actingAs($this->u['gestor-contrato'])->post("/atas/{$ata->id}/itens/".$ata->itens()->first()->id.'/adesoes', [
            'aderente_nome' => 'Prefeitura de Monte Claro (fictícia)', 'aderente_cnpj' => $cnpj ?? $this->cnpj(555), 'quantidade' => $qtd,
            'justificativa' => 'Necessidade imediata de material de expediente.', 'vantagem' => 'Preço 12% abaixo da média pesquisada no mercado.',
        ]);
    }

    public function test_adesao_respeita_50_por_cento_por_aderente_e_o_dobro_no_total(): void
    {
        $this->gerarAtas();
        $ata = Ata::first(); // registrado: 1000
        $g = $this->u['gestor-contrato'];

        $this->adesao($ata, '501')->assertSessionHas('motivos');
        $this->adesao($ata, '500')->assertSessionHas('sucesso');
        $ad = AtaAdesao::first();
        $this->actingAs($g)->post("/atas/{$ata->id}/adesoes/{$ad->id}/decidir", ['acao' => 'autorizar'])->assertSessionHas('sucesso');

        // O mesmo aderente já usou os 50%.
        $this->adesao($ata, '1')->assertSessionHas('motivos');

        // Outros aderentes completam o dobro: 3 x 500 = 1500 e depois 1 x 500 = 2000.
        foreach ([666, 777, 888] as $n) {
            $this->adesao($ata, '500', $this->cnpj($n))->assertSessionHas('sucesso');
            $this->actingAs($g)->post("/atas/{$ata->id}/adesoes/".AtaAdesao::latest('id')->first()->id.'/decidir', ['acao' => 'autorizar'])->assertSessionHas('sucesso');
        }
        $this->assertEquals(2000.0, $ata->itens()->first()->autorizadoEmAdesoes());
        $this->adesao($ata, '1', $this->cnpj(999))->assertSessionHas('motivos'); // acima do dobro
    }

    public function test_consumo_da_adesao_nao_passa_do_autorizado_e_nao_gasta_o_saldo_proprio(): void
    {
        $this->gerarAtas();
        $ata = Ata::first();
        $ai = $ata->itens()->first();
        $g = $this->u['gestor-contrato'];
        $this->adesao($ata, '100');
        $ad = AtaAdesao::first();

        // Sem autorização, não consome.
        $this->actingAs($g)->post("/atas/{$ata->id}/itens/{$ai->id}/consumos", ['quantidade' => '10', 'referencia' => 'Carona 1', 'adesao_id' => $ad->id])->assertSessionHas('motivos');

        $this->actingAs($g)->post("/atas/{$ata->id}/adesoes/{$ad->id}/decidir", ['acao' => 'autorizar']);
        $this->actingAs($g)->post("/atas/{$ata->id}/itens/{$ai->id}/consumos", ['quantidade' => '101', 'referencia' => 'Carona 1', 'adesao_id' => $ad->id])->assertSessionHas('motivos');
        $this->actingAs($g)->post("/atas/{$ata->id}/itens/{$ai->id}/consumos", ['quantidade' => '100', 'referencia' => 'Carona 1', 'adesao_id' => $ad->id])->assertSessionHas('sucesso');

        $this->assertEquals(1000.0, $ai->refresh()->saldo()); // o saldo do gerenciador não mexeu
        $this->assertEquals(100.0, $ai->consumidoEmAdesoes());
    }

    public function test_negar_adesao_exige_motivo_e_adesao_exige_justificativa(): void
    {
        $this->gerarAtas();
        $ata = Ata::first();
        $this->adesao($ata, '10');
        $ad = AtaAdesao::first();

        $this->actingAs($this->u['gestor-contrato'])->post("/atas/{$ata->id}/adesoes/{$ad->id}/decidir", ['acao' => 'negar'])->assertSessionHas('motivos');
        $this->actingAs($this->u['gestor-contrato'])->post("/atas/{$ata->id}/adesoes/{$ad->id}/decidir", ['acao' => 'negar', 'motivo' => 'Preço já não é vantajoso.'])->assertSessionHas('sucesso');

        $this->actingAs($this->u['gestor-contrato'])->post("/atas/{$ata->id}/itens/".$ata->itens()->first()->id.'/adesoes', ['aderente_nome' => 'X', 'aderente_cnpj' => $this->cnpj(555), 'quantidade' => '1', 'justificativa' => 'curta', 'vantagem' => 'curta'])->assertSessionHas('motivos');
    }

    public function test_prorrogacao_uma_vez_com_vantagem_e_ate_dois_anos(): void
    {
        $this->gerarAtas();
        $ata = Ata::first();
        $g = $this->u['gestor-contrato'];

        $this->actingAs($g)->post("/atas/{$ata->id}/prorrogar", ['vigencia_fim' => today()->addYears(2)->addDay()->toDateString(), 'vantagem' => 'Preço continua 10% abaixo da média.'])->assertSessionHas('motivos');
        $this->actingAs($g)->post("/atas/{$ata->id}/prorrogar", ['vigencia_fim' => today()->addYears(2)->subDay()->toDateString(), 'vantagem' => 'Preço continua 10% abaixo da média.'])->assertSessionHas('sucesso');
        $this->assertTrue($ata->refresh()->prorrogada);
        $this->actingAs($g)->post("/atas/{$ata->id}/prorrogar", ['vigencia_fim' => today()->addYears(2)->toDateString(), 'vantagem' => 'Preço continua 10% abaixo da média.'])->assertSessionHas('motivos');
    }

    public function test_telas_de_atas_abrem_e_respeitam_perfil_e_orgao(): void
    {
        $this->gerarAtas();
        $ata = Ata::first();

        $this->actingAs($this->u['gestor-contrato'])->get('/atas')->assertOk()->assertSee($ata->identificacao());
        $this->actingAs($this->u['gestor-contrato'])->get("/atas/{$ata->id}")->assertOk()->assertSee('Pedido de adesão');
        $this->actingAs($this->u['auditor'])->post('/atas', ['processo_id' => 1])->assertForbidden();

        $outro = $this->usuario('gestor-contrato', Orgao::factory()->create(['sigla' => 'OUT', 'cnpj' => '11.222.333/0002-62']));
        $this->actingAs($outro)->get("/atas/{$ata->id}")->assertNotFound();
    }

    // Reequilíbrio e rescisão

    private function contrato(float $valor = 100000): Contrato
    {
        return Contrato::create([
            'orgao_id' => $this->orgao->id, 'fornecedor_id' => $this->fornecedor('99.000.444/0001-94')->id, 'gestor_id' => $this->u['gestor-contrato']->id, 'fiscal_id' => $this->u['fiscal']->id,
            'numero' => (string) random_int(1, 99999), 'ano' => 2026, 'objeto' => 'Serviço de limpeza', 'categoria' => 6, 'servico_continuo' => true,
            'valor_inicial' => $valor, 'valor_global' => $valor, 'assinatura' => today()->subMonths(2), 'vigencia_inicio' => today()->subMonths(2), 'vigencia_fim' => today()->addYear(),
        ]);
    }

    public function test_reequilibrio_deferido_cria_aditivo_e_nao_conta_no_limite_de_25_por_cento(): void
    {
        $c = $this->contrato(100000);
        $g = $this->u['gestor-contrato'];

        $this->actingAs($g)->post("/contratos/{$c->id}/reequilibrios", ['fundamento' => 'fato_imprevisivel', 'motivo' => 'curto', 'valor_pedido' => '30.000,00'])->assertSessionHas('erro');
        $this->actingAs($g)->post("/contratos/{$c->id}/reequilibrios", ['fundamento' => 'fato_imprevisivel', 'motivo' => 'Aumento extraordinário do combustível, não previsto na matriz de riscos, elevou o custo de transporte.', 'valor_pedido' => '30.000,00'])->assertSessionHas('sucesso');
        $r = Reequilibrio::firstOrFail();

        // Quem pediu não decide; só a autoridade decide; valor acima do pedido é recusado.
        $this->actingAs($g)->post("/contratos/{$c->id}/reequilibrios/{$r->id}/decidir", ['acao' => 'deferir', 'valor_concedido' => '10.000,00', 'parecer' => 'Parecer técnico favorável ao pedido.'])->assertForbidden();
        $aut = $this->u['autoridade'];
        $this->actingAs($aut)->post("/contratos/{$c->id}/reequilibrios/{$r->id}/decidir", ['acao' => 'deferir', 'valor_concedido' => '30.000,01', 'parecer' => 'Parecer técnico favorável ao pedido.'])->assertSessionHas('motivos');
        $this->actingAs($aut)->post("/contratos/{$c->id}/reequilibrios/{$r->id}/decidir", ['acao' => 'deferir', 'valor_concedido' => '30.000,00', 'parecer' => 'Parecer técnico favorável ao pedido.'])->assertSessionHas('sucesso');

        $c->refresh();
        $this->assertEquals(130000.0, (float) $c->valor_global);
        $this->assertSame('deferido', $r->refresh()->situacao);
        $this->assertTrue($c->termos()->first()->reequilibrio);
        $this->assertEquals(0.0, $c->totalAcrescimos()); // não conta no art. 125

        // Ainda cabe o acréscimo unilateral de até 25%.
        $this->actingAs($g)->post("/contratos/{$c->id}/termos", ['tipo' => 'aditivo_valor', 'numero' => 'AD1', 'assinatura' => today()->toDateString(), 'valor_acrescimo' => '25.000,00', 'justificativa' => 'Acréscimo de postos de trabalho no novo prédio.'])->assertSessionHasNoErrors();
    }

    public function test_reequilibrio_indeferido_nao_altera_o_contrato(): void
    {
        $c = $this->contrato(100000);
        $this->actingAs($this->u['gestor-contrato'])->post("/contratos/{$c->id}/reequilibrios", ['fundamento' => 'fato_do_principe', 'motivo' => 'Nova taxa municipal criada após a proposta elevou o custo do serviço.', 'valor_pedido' => '5.000,00']);
        $r = Reequilibrio::firstOrFail();

        $this->actingAs($this->u['autoridade'])->post("/contratos/{$c->id}/reequilibrios/{$r->id}/decidir", ['acao' => 'indeferir', 'parecer' => 'Risco alocado ao contratado na matriz de riscos.'])->assertSessionHas('sucesso');

        $this->assertEquals(100000.0, (float) $c->refresh()->valor_global);
        $this->assertSame('indeferido', $r->refresh()->situacao);
        $this->assertSame(0, $c->termos()->count());
    }

    public function test_rescisao_unilateral_exige_comunicacao_e_so_conclui_apos_a_defesa_ou_o_prazo(): void
    {
        $c = $this->contrato();
        $g = $this->u['gestor-contrato'];
        $base = ['modalidade' => 'unilateral', 'motivo' => 'descumprimento', 'fundamentacao' => 'Entregas atrasadas por três meses seguidos, conforme relatórios do fiscal.'];

        $this->actingAs($g)->post("/contratos/{$c->id}/rescisoes", $base)->assertSessionHas('motivos'); // sem comunicação
        $this->actingAs($g)->post("/contratos/{$c->id}/rescisoes", $base + ['notificado_em' => today()->toDateString(), 'defesa_ate' => today()->addDays(5)->toDateString()])->assertSessionHas('sucesso');
        $r = Rescisao::firstOrFail();
        $aut = $this->u['autoridade'];

        $this->actingAs($aut)->post("/contratos/{$c->id}/rescisoes/{$r->id}/concluir", ['data_efeito' => today()->toDateString()])->assertSessionHas('motivos'); // prazo correndo

        $this->actingAs($g)->post("/contratos/{$c->id}/rescisoes/{$r->id}/defesa", ['defesa' => 'Contratado alega greve dos transportadores.'])->assertSessionHas('sucesso');
        $this->actingAs($aut)->post("/contratos/{$c->id}/rescisoes/{$r->id}/concluir", ['data_efeito' => today()->toDateString()])->assertSessionHas('sucesso');

        $this->assertSame('rescindido', $c->refresh()->situacao);
        $this->assertSame('concluida', $r->refresh()->situacao);
        $this->assertSame('rescisao', $c->termos()->first()->tipo);
    }

    public function test_culpa_da_administracao_exige_informar_o_ressarcimento(): void
    {
        $c = $this->contrato();
        $this->actingAs($this->u['gestor-contrato'])->post("/contratos/{$c->id}/rescisoes", ['modalidade' => 'consensual', 'motivo' => 'interesse_publico', 'fundamentacao' => 'Acordo entre as partes, com interesse da Administração na extinção.']);
        $r = Rescisao::firstOrFail();
        $aut = $this->u['autoridade'];

        $this->actingAs($aut)->post("/contratos/{$c->id}/rescisoes/{$r->id}/concluir", ['data_efeito' => today()->toDateString(), 'culpa_administracao' => 1])->assertSessionHas('motivos');
        $this->actingAs($aut)->post("/contratos/{$c->id}/rescisoes/{$r->id}/concluir", ['data_efeito' => today()->toDateString(), 'culpa_administracao' => 1, 'valor_indenizacao' => '0'])->assertSessionHas('sucesso');
    }

    public function test_rescisao_pelo_termo_generico_e_recusada_e_so_a_autoridade_conclui(): void
    {
        $c = $this->contrato();
        $g = $this->u['gestor-contrato'];

        $this->actingAs($g)->post("/contratos/{$c->id}/termos", ['tipo' => 'rescisao', 'numero' => 'R1', 'assinatura' => today()->toDateString(), 'justificativa' => 'Tentativa de rescindir sem o procedimento.'])->assertSessionHasErrors('tipo');
        $this->assertSame('vigente', $c->refresh()->situacao);

        $this->actingAs($g)->post("/contratos/{$c->id}/rescisoes", ['modalidade' => 'consensual', 'motivo' => 'interesse_publico', 'fundamentacao' => 'Acordo entre as partes, com interesse da Administração na extinção.']);
        $this->actingAs($g)->post("/contratos/{$c->id}/rescisoes/".Rescisao::first()->id.'/concluir', ['data_efeito' => today()->toDateString()])->assertForbidden();
        $this->actingAs($g)->post("/contratos/{$c->id}/rescisoes", ['modalidade' => 'consensual', 'motivo' => 'interesse_publico', 'fundamentacao' => 'Outro processo de extinção ao mesmo tempo.'])->assertSessionHas('motivos'); // já há uma em andamento
    }

    public function test_tela_do_contrato_mostra_reequilibrio_e_extincao(): void
    {
        $c = $this->contrato();
        $this->actingAs($this->u['gestor-contrato'])->get("/contratos/{$c->id}")->assertOk()->assertSee('Reequilíbrio econômico-financeiro')->assertSee('Extinção do contrato');
    }
}
