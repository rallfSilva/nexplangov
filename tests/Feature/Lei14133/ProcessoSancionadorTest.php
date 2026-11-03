<?php

namespace Tests\Feature\Lei14133;

use App\Models\Fornecedor;
use App\Models\ProcessoSancionador;
use App\Models\SancaoFornecedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Processo sancionador de ponta a ponta (arts. 155 a 163 e 166 a 168). */
class ProcessoSancionadorTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    private Fornecedor $f;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->montarCenario();
        $this->travelTo('2026-11-09 10:00:00');
        $this->f = $this->fornecedor();
    }

    private function instaurar(string $tipo = 'impedimento', array $infracoes = ['III'], array $mais = []): ProcessoSancionador
    {
        $this->actingAs($this->u['agente-contratacao'])->post('/sancoes', $mais + [
            'fornecedor_id' => $this->f->id, 'infracoes' => $infracoes, 'sancao_proposta' => $tipo,
            'fatos' => 'Contrato fictício 1/2026: o fornecedor não entregou nenhum item até o fim do prazo de 30 dias.',
            'comissao' => [$this->u['agente-contratacao']->id, $this->u['gestor-contrato']->id], 'comissao_estaveis' => '1',
        ])->assertRedirect();

        return ProcessoSancionador::latest('id')->firstOrFail();
    }

    private function intimarEDefender(ProcessoSancionador $ps, string $defesaEm = '2026-11-25'): void
    {
        $a = $this->u['agente-contratacao'];
        $this->actingAs($a)->post("/sancoes/{$ps->id}/intimar", ['intimado_em' => '2026-11-09', 'meio' => 'E-mail com confirmação de leitura'])->assertSessionHas('sucesso');
        $this->travelTo($defesaEm.' 10:00:00');
        $this->actingAs($a)->post("/sancoes/{$ps->id}/defesa", ['texto' => 'Alega atraso do transportador, sem comprovar.', 'recebida_em' => $defesaEm, 'arquivo' => UploadedFile::fake()->create('defesa.pdf', 30, 'application/pdf')])->assertSessionHas('sucesso');
    }

    private function decidir(ProcessoSancionador $ps, array $mudar = [], ?User $quem = null): TestResponse
    {
        return $this->actingAs($quem ?? $this->u['autoridade'])->post("/sancoes/{$ps->id}/decidir", $mudar + [
            'resultado' => 'aplicada', 'sancao_tipo' => 'impedimento', 'duracao_meses' => '24', 'base_calculo' => '100.000,00', 'multa_valor' => '5.000,00',
            'dosimetria' => 'Inexecução total, sem atenuantes; prejuízo à merenda escolar; sem programa de integridade.',
            'decisao' => 'A defesa não comprovou o fato alegado. Aplica-se impedimento por 24 meses e multa de 5%.',
        ]);
    }

    public function test_fluxo_completo_com_recurso_ate_a_comunicacao_ao_ceis(): void
    {
        // Impedimento sem comissão é recusado (art. 158).
        $this->actingAs($this->u['agente-contratacao'])->post('/sancoes', [
            'fornecedor_id' => $this->f->id, 'infracoes' => ['III'], 'sancao_proposta' => 'impedimento', 'fatos' => str_repeat('Fato fictício descrito. ', 3),
        ])->assertSessionHas('motivos', fn ($m) => str_contains(implode(' ', $m), 'art. 158'));

        $ps = $this->instaurar();
        $this->assertSame('PS-2026-001', $ps->numero);
        $this->intimarEDefender($ps);
        $ps->refresh();
        $this->assertSame('2026-12-01', $ps->prazo_defesa->toDateString()); // 15 dias úteis, com o feriado de 20/11
        Storage::disk('local')->assertExists($ps->intimacao_caminho);
        $this->assertSame('decisao', $ps->situacao);

        // Quem conduz não decide.
        $this->decidir($ps, [], $this->u['agente-contratacao'])->assertForbidden();
        $this->travelTo('2026-11-26 10:00:00');
        $this->decidir($ps)->assertSessionHas('sucesso');
        $ps->refresh();
        $this->assertSame('2026-12-17', $ps->prazo_recurso->toDateString());

        // Efeito suspensivo: não vira definitiva no prazo de recurso (art. 168).
        $this->actingAs($this->u['agente-contratacao'])->post("/sancoes/{$ps->id}/definitiva")->assertSessionHas('motivos');
        $this->assertNull($this->f->impedidoEm());

        $this->travelTo('2026-12-01 10:00:00');
        $this->actingAs($this->u['agente-contratacao'])->post("/sancoes/{$ps->id}/recurso", ['texto' => 'Pede a redução da multa e do prazo.', 'recebido_em' => '2026-12-01'])->assertSessionHas('sucesso');
        $this->assertSame('recurso', $ps->refresh()->recurso_meio);

        // A superior só decide depois de quem decidiu manter; e quem manteve não decide o recurso.
        $superior = $this->usuario('autoridade', $this->orgao);
        $improvido = ['resultado' => 'improvido', 'decisao' => 'Razões não afastam a gravidade apurada; mantém-se a decisão.'];
        $this->actingAs($superior)->post("/sancoes/{$ps->id}/decidir-recurso", $improvido)->assertSessionHas('motivos');
        $this->actingAs($this->u['autoridade'])->post("/sancoes/{$ps->id}/reconsiderar", ['acao' => 'manter', 'texto' => 'Mantenho a decisão pelos próprios fundamentos.'])->assertSessionHas('sucesso');
        $this->actingAs($this->u['autoridade'])->post("/sancoes/{$ps->id}/decidir-recurso", $improvido)->assertSessionHas('motivos');
        $this->actingAs($superior)->post("/sancoes/{$ps->id}/decidir-recurso", $improvido)->assertSessionHas('sucesso');

        $this->actingAs($this->u['agente-contratacao'])->post("/sancoes/{$ps->id}/definitiva")->assertSessionHas('sucesso');
        $ps->refresh();
        $s = SancaoFornecedor::where('processo_sancionador_id', $ps->id)->firstOrFail();
        $this->assertSame('2028-11-30', $s->fim->toDateString());
        $this->assertSame('ente', $s->abrangencia);
        $this->assertNotNull($this->f->impedidoEm());
        $this->assertTrue($ps->ceisPendente());
        $this->assertSame('2026-12-22', $ps->prazo_ceis->toDateString());

        // Planilha para o CEIS e registro da comunicação.
        $this->actingAs($this->u['agente-contratacao'])->get('/sancoes')->assertOk()->assertSee('a informar no CEIS/CNEP');
        $csv = $this->actingAs($this->u['agente-contratacao'])->get('/sancoes/ceis.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('99000333000188', $csv);
        $this->assertStringContainsString('art. 155, III', $csv);
        $this->actingAs($this->u['agente-contratacao'])->post("/sancoes/{$ps->id}/ceis", ['informado_em' => '2026-12-01', 'protocolo' => 'CEIS 2026/000123 (fictício)'])->assertSessionHas('sucesso');
        $this->assertFalse($ps->refresh()->ceisPendente());
        $this->actingAs($this->u['auditor'])->get("/sancoes/{$ps->id}")->assertOk()->assertSee('CEIS 2026/000123');
    }

    public function test_inidoneidade_exige_parecer_e_a_reconsideracao_pode_afastar_a_sancao(): void
    {
        $ps = $this->instaurar('inidoneidade', ['IX']);
        $this->intimarEDefender($ps);
        $inid = ['sancao_tipo' => 'inidoneidade', 'duracao_meses' => '48', 'multa_valor' => '', 'base_calculo' => ''];

        $this->decidir($ps, $inid)->assertSessionHas('motivos', fn ($m) => str_contains(implode(' ', $m), '§ 6º'));
        $this->actingAs($this->u['juridico'])->post("/sancoes/{$ps->id}/parecer", ['texto' => 'Há prova documental da fraude; cabe a declaração de inidoneidade.'])->assertSessionHas('sucesso');
        $this->decidir($ps, ['duracao_meses' => '30'] + $inid)->assertSessionHas('motivos'); // mínimo de 3 anos
        $this->decidir($ps, $inid)->assertSessionHas('sucesso');

        $this->actingAs($this->u['agente-contratacao'])->post("/sancoes/{$ps->id}/recurso", ['texto' => 'Pedido de reconsideração com documento novo.', 'recebido_em' => '2026-11-26'])->assertSessionHas('sucesso');
        $ps->refresh();
        $this->assertSame('reconsideracao', $ps->recurso_meio);
        $this->assertNotNull($ps->prazo_decisao_recurso);

        // Na inidoneidade, a própria autoridade decide o pedido (art. 167).
        $this->actingAs($this->u['autoridade'])->post("/sancoes/{$ps->id}/decidir-recurso", ['resultado' => 'provido', 'decisao' => 'O documento novo afasta a fraude imputada; sanção afastada.'])->assertSessionHas('sucesso');
        $this->assertSame('arquivado', $ps->refresh()->situacao);
        $this->assertSame(0, SancaoFornecedor::count());
    }

    public function test_revelia_multa_fora_dos_limites_advertencia_incompativel_e_defesa_fora_do_prazo(): void
    {
        $ps = $this->instaurar('multa', ['VII'], ['comissao' => [], 'comissao_estaveis' => null]);
        $this->actingAs($this->u['agente-contratacao'])->post("/sancoes/{$ps->id}/intimar", ['intimado_em' => '2026-11-09', 'meio' => 'Carta com AR'])->assertSessionHas('sucesso');

        // Antes do fim do prazo de defesa, não decide.
        $multa = ['sancao_tipo' => 'multa', 'duracao_meses' => '', 'base_calculo' => '10.000,00', 'multa_valor' => '400,00'];
        $this->decidir($ps, $multa)->assertSessionHas('motivos');

        $this->travelTo('2026-12-02 10:00:00');
        $this->actingAs($this->u['agente-contratacao'])->post("/sancoes/{$ps->id}/defesa", ['texto' => 'Defesa entregue depois do prazo.', 'recebida_em' => '2026-12-02'])
            ->assertSessionHas('motivos', fn ($m) => str_contains(implode(' ', $m), 'fora do prazo'));

        $this->decidir($ps, ['multa_valor' => '40,00'] + $multa)->assertSessionHas('motivos', fn ($m) => str_contains(implode(' ', $m), '0,5%')); // mínimo é R$ 50
        $this->decidir($ps, ['multa_valor' => '3.500,00'] + $multa)->assertSessionHas('motivos'); // acima de 30%
        $this->decidir($ps, ['sancao_tipo' => 'advertencia'] + $multa)->assertSessionHas('motivos', fn ($m) => str_contains(implode(' ', $m), '§ 2º'));
        $this->decidir($ps, ['multa_valor' => '1.000,00'] + $multa)->assertSessionHas('sucesso');
        $this->assertSame('decidido', $ps->refresh()->situacao);
    }

    public function test_reabilitacao_depois_de_um_ano_com_os_requisitos(): void
    {
        $ps = $this->instaurar();
        $this->intimarEDefender($ps);
        $this->decidir($ps)->assertSessionHas('sucesso');
        $this->travelTo('2026-12-18 10:00:00');
        $this->actingAs($this->u['agente-contratacao'])->post("/sancoes/{$ps->id}/definitiva")->assertSessionHas('sucesso');

        $pedido = ['requisitos' => ['reparacao', 'multa_paga', 'condicoes', 'parecer'], 'fundamento' => 'Dano reparado e multa paga, conforme comprovantes juntados aos autos.'];
        $this->actingAs($this->u['autoridade'])->post("/sancoes/{$ps->id}/reabilitar", $pedido)->assertSessionHas('motivos', fn ($m) => str_contains(implode(' ', $m), 'art. 163, III'));

        $this->travelTo('2027-12-20 10:00:00');
        $this->actingAs($this->u['autoridade'])->post("/sancoes/{$ps->id}/reabilitar", ['requisitos' => ['reparacao']] + $pedido)->assertSessionHas('motivos');
        $this->actingAs($this->u['autoridade'])->post("/sancoes/{$ps->id}/reabilitar", $pedido)->assertSessionHas('sucesso');
        $this->assertSame('reabilitado', $ps->refresh()->situacao);
        $this->assertNull($this->f->impedidoEm());
    }

    public function test_telas_e_permissoes(): void
    {
        $ps = $this->instaurar();
        $this->actingAs($this->u['agente-contratacao'])->get('/sancoes/novo')->assertOk()->assertSee('Infrações apontadas');
        $this->actingAs($this->u['agente-contratacao'])->get("/sancoes/{$ps->id}")->assertOk()->assertSee('Intimar o fornecedor');
        $this->actingAs($this->u['requisitante'])->get('/sancoes')->assertForbidden();
        $this->actingAs($this->u['autoridade'])->get('/sancoes/novo')->assertForbidden();
        $this->actingAs($this->u['autoridade'])->get('/sancoes/ceis.csv')->assertForbidden();
        $this->actingAs($this->u['gestor-contrato'])->post("/sancoes/{$ps->id}/parecer", ['texto' => str_repeat('x', 40)])->assertForbidden();
    }
}
