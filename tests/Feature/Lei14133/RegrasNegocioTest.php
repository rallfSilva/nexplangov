<?php

namespace Tests\Feature\Lei14133;

use App\Licitacao\Enums\AmparoLegal;
use App\Licitacao\Regras\LimiteDispensa;
use App\Models\Contrato;
use App\Models\Fornecedor;
use App\Models\Orgao;
use App\Models\RegistroAuditoria;
use App\Models\SolicitacaoTitular;
use App\Support\Auditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegrasNegocioTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    public function test_dispensa_bloqueia_fracionamento_na_mesma_natureza(): void
    {
        // Já há R$ 60.000 em dispensa de material de expediente em 2026 (limite do inciso II: R$ 65.492,11).
        $anterior = $this->processo(['modalidade' => 8, 'amparo_legal' => 19, 'natureza_despesa' => '3.3.90.30.16'], 'homologado');
        $anterior->forceFill(['valor_estimado' => 60000, 'valor_homologado' => 60000])->save();

        $p = $this->processo(['modalidade' => 8, 'amparo_legal' => 19, 'natureza_despesa' => '3.3.90.30.16']);
        $this->itemComPreco($p, 100, 100); // R$ 10.000
        foreach (['dfd', 'termo_referencia', 'dotacao', 'habilitacao', 'razao_escolha', 'justificativa_preco'] as $tipo) {
            $this->concluirDocumento($p, $tipo, $this->u['planejamento']);
        }

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/tramitar", ['destino' => 'analise_juridica'])
            ->assertSessionHas('motivos', fn ($m) => collect($m)->contains(fn ($x) => str_contains($x, 'fracionamento')));

        // Outra natureza não soma.
        $p->forceFill(['natureza_despesa' => '3.3.90.30.22'])->save();
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/tramitar", ['destino' => 'analise_juridica'])->assertSessionHas('sucesso');
    }

    public function test_dispensa_em_dobro_para_consorcio(): void
    {
        $this->orgao->update(['limite_dispensa_em_dobro' => true]);
        $r = app(LimiteDispensa::class)->verificar($this->orgao->fresh(), 2026, AmparoLegal::Art75II, 100000, null);

        $this->assertTrue($r['permitido']);
        $this->assertSame(130984.22, $r['limite']);
    }

    public function test_amparo_incompativel_com_modalidade_e_recusado(): void
    {
        $this->actingAs($this->u['planejamento'])->post('/processos', [
            'numero' => '1', 'ano' => 2026, 'objeto' => 'Obra de pavimentação de ruas do centro', 'unidade_id' => $this->unidade->id,
            'modalidade' => 8, 'amparo_legal' => 19, 'criterio_julgamento' => 1, 'modo_disputa' => 4, 'categoria' => 7, 'natureza_despesa' => '4.4.90.51',
        ])->assertSessionHasErrors('amparo_legal'); // obra precisa do inciso I
    }

    public function test_contrato_exige_gestor_e_fiscal_diferentes_e_vigencia_de_continuo_ate_5_anos(): void
    {
        $f = $this->fornecedor();
        $base = ['numero' => '10', 'ano' => 2026, 'tipo' => 1, 'objeto' => 'Serviço contínuo de vigilância patrimonial', 'categoria' => 6, 'fornecedor_id' => $f->id, 'valor_inicial' => '100.000,00', 'assinatura' => today()->format('Y-m-d'), 'vigencia_inicio' => today()->format('Y-m-d'), 'servico_continuo' => '1'];

        $this->actingAs($this->u['gestor-contrato'])->post('/contratos', $base + ['gestor_id' => $this->u['gestor-contrato']->id, 'fiscal_id' => $this->u['gestor-contrato']->id, 'vigencia_fim' => today()->addYears(6)->format('Y-m-d')])
            ->assertSessionHasErrors(['fiscal_id', 'vigencia_fim']);

        $this->actingAs($this->u['gestor-contrato'])->post('/contratos', $base + ['gestor_id' => $this->u['gestor-contrato']->id, 'fiscal_id' => $this->u['fiscal']->id, 'vigencia_fim' => today()->addYear()->format('Y-m-d')])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Contrato::count());
    }

    public function test_aditivo_acima_de_25_por_cento_e_bloqueado(): void
    {
        $c = $this->contrato(100000);

        $this->actingAs($this->u['gestor-contrato'])->post("/contratos/{$c->id}/termos", ['tipo' => 'aditivo_valor', 'numero' => '1', 'assinatura' => today()->format('Y-m-d'), 'valor_acrescimo' => '20.000,00', 'justificativa' => 'Acréscimo de postos de trabalho no novo prédio.'])->assertSessionHasNoErrors();
        $this->actingAs($this->u['gestor-contrato'])->post("/contratos/{$c->id}/termos", ['tipo' => 'aditivo_valor', 'numero' => '2', 'assinatura' => today()->format('Y-m-d'), 'valor_acrescimo' => '6.000,00', 'justificativa' => 'Mais um acréscimo de postos de trabalho.'])->assertSessionHasErrors('valor_acrescimo');

        $this->assertEquals(120000, (float) $c->fresh()->valor_global);
    }

    public function test_reajuste_apostilado_aumenta_a_base_do_art_125(): void
    {
        $c = $this->contrato(100000);
        $this->actingAs($this->u['gestor-contrato'])->post("/contratos/{$c->id}/termos", ['tipo' => 'apostilamento', 'numero' => 'AP1', 'assinatura' => today()->format('Y-m-d'), 'valor_reajuste' => '10.000,00', 'justificativa' => 'Reajuste anual pelo IPCA conforme cláusula do contrato.'])->assertSessionHasNoErrors();

        // 25% de 110.000 = 27.500
        $this->actingAs($this->u['gestor-contrato'])->post("/contratos/{$c->id}/termos", ['tipo' => 'aditivo_valor', 'numero' => '1', 'assinatura' => today()->format('Y-m-d'), 'valor_acrescimo' => '27.500,00', 'justificativa' => 'Acréscimo dentro do limite atualizado.'])->assertSessionHasNoErrors();
    }

    public function test_fornecedor_impedido_nao_pode_ser_contratado(): void
    {
        $f = $this->fornecedor();
        $f->sancoes()->create(['orgao_id' => $this->orgao->id, 'tipo' => 'impedimento', 'inicio' => today()->subDay(), 'fim' => today()->addYear(), 'fundamento' => 'Inexecução total do contrato anterior (art. 155, III).']);

        $this->actingAs($this->u['gestor-contrato'])->post('/contratos', ['numero' => '11', 'ano' => 2026, 'tipo' => 1, 'objeto' => 'Fornecimento de água mineral', 'categoria' => 2, 'fornecedor_id' => $f->id, 'gestor_id' => $this->u['gestor-contrato']->id, 'fiscal_id' => $this->u['fiscal']->id, 'valor_inicial' => '1.000,00', 'assinatura' => today()->format('Y-m-d'), 'vigencia_inicio' => today()->format('Y-m-d'), 'vigencia_fim' => today()->addMonths(6)->format('Y-m-d')])
            ->assertSessionHasErrors('fornecedor_id');
    }

    public function test_cnpj_invalido_e_recusado_e_cpf_fica_cifrado(): void
    {
        $this->actingAs($this->u['planejamento'])->post('/fornecedores', ['tipo_pessoa' => 'PJ', 'documento' => '11.222.333/0001-00', 'razao_social' => 'X'])->assertSessionHasErrors('documento');

        $this->actingAs($this->u['planejamento'])->post('/fornecedores', ['tipo_pessoa' => 'PF', 'documento' => '529.982.247-25', 'razao_social' => 'Pessoa Física Teste'])->assertSessionHasNoErrors();

        $f = Fornecedor::firstOrFail();
        $this->assertSame('***.982.247-**', $f->documento);
        $this->assertSame('52998224725', $f->documentoCompleto());
        $bruto = DB::table('fornecedores')->where('id', $f->id)->value('documento_cifrado');
        $this->assertStringNotContainsString('52998224725', $bruto);
        $this->assertStringNotContainsString('52998224725', json_encode(RegistroAuditoria::pluck('valores_novos')));

        // Busca pelo CPF completo funciona sem expor o dado.
        $this->actingAs($this->u['planejamento'])->get('/fornecedores?busca=529.982.247-25')->assertSee('Pessoa Física Teste');
    }

    public function test_canal_do_titular_registra_pedido_com_prazo_de_15_dias(): void
    {
        $this->post('/lgpd/solicitar', ['orgao_id' => $this->orgao->id, 'nome' => 'Fulana', 'email' => 'fulana@exemplo.com', 'cpf' => '529.982.247-25', 'tipo' => 'acesso', 'descricao' => 'Quero ver os meus dados.', 'ciente' => '1'])
            ->assertRedirect()->assertSessionHas('sucesso');

        $s = SolicitacaoTitular::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(today()->addDays(15)->format('Y-m-d'), $s->prazo_em->format('Y-m-d'));
        $this->assertStringStartsWith('LGPD-', $s->protocolo);
        $this->assertStringNotContainsString('52998224725', DB::table('solicitacoes_titular')->value('cpf_cifrado'));
    }

    public function test_outro_orgao_nao_ve_processo_nem_contrato(): void
    {
        $p = $this->processo();
        $c = $this->contrato(5000);
        $alheio = $this->usuario('planejamento', Orgao::factory()->create());

        $this->actingAs($alheio)->get("/processos/{$p->id}")->assertNotFound();
        $this->actingAs($alheio)->get("/contratos/{$c->id}")->assertNotFound();
        $this->actingAs($alheio)->get('/processos')->assertDontSee($p->objeto);
    }

    public function test_requisitante_nao_acessa_processos_e_controle_interno_so_le(): void
    {
        $p = $this->processo();
        $this->actingAs($this->u['requisitante'])->get('/processos')->assertForbidden();
        $this->actingAs($this->u['auditor'])->get("/processos/{$p->id}")->assertOk();
        $this->actingAs($this->u['auditor'])->get('/processos/novo')->assertForbidden();
    }

    public function test_cadeia_de_auditoria_fica_integra(): void
    {
        $this->processo();
        $this->contrato(1000);

        $this->assertNull(Auditoria::verificarCadeia());
        $this->assertGreaterThan(5, RegistroAuditoria::withoutGlobalScopes()->count());
    }

    private function contrato(float $valor): Contrato
    {
        return Contrato::create([
            'orgao_id' => $this->orgao->id, 'fornecedor_id' => $this->fornecedor('99.000.444/0001-94')->id, 'gestor_id' => $this->u['gestor-contrato']->id, 'fiscal_id' => $this->u['fiscal']->id,
            'numero' => (string) random_int(1, 99999), 'ano' => 2026, 'objeto' => 'Serviço de limpeza', 'categoria' => 6, 'servico_continuo' => true,
            'valor_inicial' => $valor, 'valor_global' => $valor, 'assinatura' => today(), 'vigencia_inicio' => today(), 'vigencia_fim' => today()->addYear(),
        ]);
    }
}
