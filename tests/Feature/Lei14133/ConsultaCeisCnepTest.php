<?php

namespace Tests\Feature\Lei14133;

use App\Models\ConsultaImpedimento;
use App\Models\Contrato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Consulta automática ao CEIS e ao CNEP antes de contratar (art. 161 e art. 156). */
class ConsultaCeisCnepTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        config(['nexplangov.transparencia.chave' => 'chave-de-teste', 'nexplangov.transparencia.url' => 'https://api.transparencia.teste/api-de-dados']);
    }

    private function fakeApi(array $ceis = [], array $cnep = []): void
    {
        Http::fake([
            'api.transparencia.teste/api-de-dados/ceis*' => Http::response($ceis),
            'api.transparencia.teste/api-de-dados/cnep*' => Http::response($cnep),
        ]);
    }

    private function sancao(string $tipo, string $inicio = '01/01/2025', string $fim = '31/12/2030', int $id = 1): array
    {
        return ['id' => $id, 'tipoSancao' => ['descricaoResumida' => $tipo], 'orgaoSancionador' => ['nome' => 'Prefeitura de Outra Cidade (fictícia)'], 'dataInicioSancao' => $inicio, 'dataFimSancao' => $fim];
    }

    private function dadosContrato(int $fornecedorId): array
    {
        return ['numero' => '10', 'ano' => 2026, 'tipo' => 1, 'objeto' => 'Serviço de limpeza predial (fictício)', 'categoria' => 8, 'fornecedor_id' => $fornecedorId, 'valor_inicial' => '10.000,00',
            'assinatura' => today()->format('Y-m-d'), 'vigencia_inicio' => today()->format('Y-m-d'), 'vigencia_fim' => today()->addMonths(6)->format('Y-m-d'),
            'gestor_id' => $this->u['gestor-contrato']->id, 'fiscal_id' => $this->u['fiscal']->id];
    }

    public function test_consulta_envia_a_chave_e_o_cnpj_e_guarda_o_resultado(): void
    {
        $this->fakeApi();
        $f = $this->fornecedor();

        $this->actingAs($this->u['agente-contratacao'])->post("/fornecedores/{$f->id}/ceis-cnep")->assertSessionHas('sucesso', 'CEIS e CNEP: nada consta.');

        Http::assertSent(fn (Request $r) => $r->hasHeader('chave-api-dados', 'chave-de-teste') && $r['codigoSancionado'] === '99000333000188');
        $c = ConsultaImpedimento::firstOrFail();
        $this->assertSame('regular', $c->situacao);
        $this->assertSame(64, strlen($c->hash_resposta));
    }

    public function test_inidoneidade_no_ceis_impede_o_contrato(): void
    {
        $this->fakeApi([$this->sancao('Declaração de Inidoneidade')]);
        $f = $this->fornecedor();

        $this->actingAs($this->u['gestor-contrato'])->post('/contratos', $this->dadosContrato($f->id))
            ->assertSessionHasErrors('fornecedor_id');
        $this->assertStringContainsString('art. 156, IV', session('errors')->first('fornecedor_id'));
        $this->assertSame(0, Contrato::count());
    }

    public function test_impedimento_de_outro_ente_libera_depois_da_analise_de_abrangencia(): void
    {
        $this->fakeApi([$this->sancao('Impedimento/proibição de contratar com prazo determinado')]);
        $f = $this->fornecedor();

        $this->actingAs($this->u['gestor-contrato'])->post('/contratos', $this->dadosContrato($f->id))->assertSessionHasErrors('fornecedor_id');
        $consulta = ConsultaImpedimento::latest('id')->firstOrFail();

        // Só a autoridade registra a análise.
        $this->actingAs($this->u['agente-contratacao'])->post("/fornecedores/{$f->id}/ceis-cnep/{$consulta->id}/ressalva", ['justificativa' => str_repeat('Sanção aplicada por outro município. ', 2)])->assertForbidden();
        $this->actingAs($this->u['autoridade'])->get("/fornecedores/{$f->id}/ceis-cnep")->assertOk()->assertSee('Análise de abrangência');
        $this->actingAs($this->u['autoridade'])->post("/fornecedores/{$f->id}/ceis-cnep/{$consulta->id}/ressalva", ['justificativa' => 'Sanção aplicada por outro município; alcança só aquele ente (art. 156, § 4º).'])
            ->assertSessionHas('sucesso');

        $this->actingAs($this->u['gestor-contrato'])->post('/contratos', $this->dadosContrato($f->id))->assertSessionHasNoErrors();
        $this->assertSame(1, Contrato::count());
    }

    public function test_analise_vale_para_as_mesmas_sancoes_em_nova_consulta(): void
    {
        $this->fakeApi([$this->sancao('Suspensão')]);
        $f = $this->fornecedor();
        $this->actingAs($this->u['agente-contratacao'])->post("/fornecedores/{$f->id}/ceis-cnep");
        ConsultaImpedimento::firstOrFail()->update(['ressalva_justificativa' => 'Sanção restrita ao ente que a aplicou, conferida.', 'ressalva_por' => $this->u['autoridade']->id, 'ressalva_em' => now()]);

        $this->actingAs($this->u['agente-contratacao'])->post("/fornecedores/{$f->id}/ceis-cnep");

        $this->assertNotNull(ConsultaImpedimento::latest('id')->first()->ressalva_em);
    }

    public function test_api_fora_do_ar_nao_trava_a_contratacao_mas_fica_registrada(): void
    {
        Http::fake(['api.transparencia.teste/*' => Http::response('erro', 503)]);
        $f = $this->fornecedor();

        $this->actingAs($this->u['gestor-contrato'])->post('/contratos', $this->dadosContrato($f->id))->assertSessionHasNoErrors();
        $this->assertSame('indisponivel', ConsultaImpedimento::firstOrFail()->situacao);
    }

    public function test_sem_chave_a_consulta_fica_desligada(): void
    {
        config(['nexplangov.transparencia.chave' => null]);
        Http::fake();
        $f = $this->fornecedor();

        $this->actingAs($this->u['gestor-contrato'])->post('/contratos', $this->dadosContrato($f->id))->assertSessionHasNoErrors();
        Http::assertNothingSent();
    }
}
