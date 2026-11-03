<?php

namespace Tests\Feature\Lei14133;

use App\Licitacao\Ia\ClienteIa;
use App\Licitacao\Ia\IaIndisponivel;
use App\Models\Capacitacao;
use App\Models\Orgao;
use App\Models\UsoIa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/** NexMind IA, capacitação (art. 7º, II) e painel de governança (art. 11). */
class IaGovernancaTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    /** @var list<string> */
    public array $pedidos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        config(['nexplangov.ia.habilitada' => true]);

        $teste = $this;
        $this->app->instance(ClienteIa::class, new class($teste) implements ClienteIa
        {
            public function __construct(private $teste) {}

            public function responder(string $sistema, string $pedido): array
            {
                $this->teste->pedidos[] = $pedido;

                return ['texto' => 'Texto sugerido pela IA (art. 18).', 'tokens_entrada' => 120, 'tokens_saida' => 30];
            }
        });
    }

    private function url($p, string $tipo = 'etp'): string
    {
        return "/processos/{$p->id}/documentos/{$tipo}/ia";
    }

    public function test_sugere_texto_registra_uso_e_nao_salva_a_peca(): void
    {
        $p = $this->processo();
        $r = $this->actingAs($this->u['planejamento'])->postJson($this->url($p), ['acao' => 'sugerir', 'campo' => 'i_necessidade', 'conteudo' => ['i_necessidade' => 'Rascunho']]);

        $r->assertOk()->assertJsonPath('texto', 'Texto sugerido pela IA (art. 18).')->assertJsonStructure(['aviso']);
        $this->assertSame(1, UsoIa::count());
        $this->assertSame(0, $p->documentos()->count());
        $this->assertSame(64, strlen(UsoIa::first()->hash_pedido));
    }

    public function test_dado_pessoal_nao_vai_para_a_ia(): void
    {
        $p = $this->processo();
        $this->actingAs($this->u['planejamento'])->postJson($this->url($p), ['acao' => 'revisar', 'conteudo' => ['i_necessidade' => 'Contato do fiscal: CPF 123.456.789-09, fiscal@vale.gov.br']])->assertOk();

        $this->assertStringNotContainsString('123.456.789-09', $this->pedidos[0]);
        $this->assertStringNotContainsString('fiscal@vale.gov.br', $this->pedidos[0]);
        $this->assertStringContainsString($p->objeto, $this->pedidos[0]);
    }

    public function test_ia_nao_redige_parecer_nem_autorizacao(): void
    {
        $p = $this->processo([], 'analise_juridica');
        $this->actingAs($this->u['juridico'])->postJson($this->url($p, 'parecer_juridico'), ['acao' => 'revisar', 'conteudo' => []])->assertStatus(422);
        $this->assertSame(0, UsoIa::count());
    }

    public function test_sem_permissao_ou_desligada_nao_usa(): void
    {
        $p = $this->processo();
        $this->actingAs($this->u['requisitante'])->postJson($this->url($p), ['acao' => 'revisar'])->assertForbidden();

        config(['nexplangov.ia.habilitada' => false]);
        $this->actingAs($this->u['planejamento'])->postJson($this->url($p), ['acao' => 'revisar'])->assertStatus(422);
        $this->actingAs($this->u['planejamento'])->get("/processos/{$p->id}/documentos/etp")->assertOk()->assertDontSee('Sugerir com a NexMind IA');
    }

    public function test_botao_aparece_para_quem_pode_editar(): void
    {
        $p = $this->processo();
        $this->actingAs($this->u['planejamento'])->get("/processos/{$p->id}/documentos/etp")->assertOk()->assertSee('Sugerir com a NexMind IA')->assertSee('Revisar esta peça');
    }

    public function test_limite_diario_por_usuario(): void
    {
        config(['nexplangov.ia.limite_diario_por_usuario' => 1]);
        $p = $this->processo();
        $this->actingAs($this->u['planejamento'])->postJson($this->url($p), ['acao' => 'revisar'])->assertOk();
        $this->actingAs($this->u['planejamento'])->postJson($this->url($p), ['acao' => 'revisar'])->assertStatus(429);
    }

    public function test_ia_fora_do_ar_responde_com_aviso(): void
    {
        $this->app->instance(ClienteIa::class, new class implements ClienteIa
        {
            public function responder(string $sistema, string $pedido): array
            {
                throw new IaIndisponivel('A IA não respondeu agora. Tente de novo em instantes.');
            }
        });
        $p = $this->processo();
        $this->actingAs($this->u['planejamento'])->postJson($this->url($p), ['acao' => 'revisar'])->assertStatus(503)->assertJsonPath('erro', 'A IA não respondeu agora. Tente de novo em instantes.');
    }

    public function test_registro_de_uso_e_imutavel(): void
    {
        $p = $this->processo();
        $this->actingAs($this->u['planejamento'])->postJson($this->url($p), ['acao' => 'revisar'])->assertOk();
        $this->expectException(LogicException::class);
        UsoIa::first()->delete();
    }

    // ----- Capacitação -----

    private function curso(array $extra = []): array
    {
        return $extra + ['curso' => 'Nova Lei de Licitações na prática', 'instituicao' => 'Escola de Governo de Vale Verde (fictícia)', 'tema' => 'licitacao', 'carga_horaria' => 40, 'concluida_em' => today()->subMonth()->toDateString(), 'escola_governo' => '1'];
    }

    public function test_cada_um_registra_a_propria_e_o_administrador_registra_de_todos(): void
    {
        $agente = $this->u['agente-contratacao'];
        $this->actingAs($agente)->post('/capacitacao', $this->curso(['user_id' => $agente->id]))->assertSessionHas('sucesso');
        $this->actingAs($agente)->post('/capacitacao', $this->curso(['user_id' => $this->u['juridico']->id]))->assertForbidden();

        $adm = $this->usuario('administrador-orgao', $this->orgao);
        $this->actingAs($adm)->post('/capacitacao', $this->curso(['user_id' => $this->u['juridico']->id]))->assertSessionHas('sucesso');
        $this->actingAs($adm)->get('/capacitacao')->assertOk()->assertSee($this->u['juridico']->name)->assertSee('Nenhum curso registrado');

        $this->assertSame(2, Capacitacao::count());
    }

    public function test_curso_futuro_ou_de_outro_orgao_e_recusado(): void
    {
        $adm = $this->usuario('administrador-orgao', $this->orgao);
        $this->actingAs($adm)->post('/capacitacao', $this->curso(['user_id' => $this->u['juridico']->id, 'concluida_em' => today()->addDay()->toDateString()]))->assertSessionHasErrors('concluida_em');

        $outro = $this->usuario('juridico', Orgao::factory()->create(['cnpj' => '99.000.666/0001-00']));
        $this->actingAs($adm)->post('/capacitacao', $this->curso(['user_id' => $outro->id]))->assertSessionHasErrors('user_id');
    }

    public function test_curso_antigo_fica_desatualizado(): void
    {
        $c = Capacitacao::create($this->curso(['orgao_id' => $this->orgao->id, 'user_id' => $this->u['juridico']->id, 'registrada_por' => $this->u['juridico']->id, 'concluida_em' => today()->subMonths(30)]));
        $this->assertFalse($c->vigente());
    }

    // ----- Governança -----

    public function test_painel_de_governanca_calcula_indicadores(): void
    {
        $this->processo(['modalidade' => 8, 'amparo_legal' => 19], 'planejamento')->forceFill(['valor_estimado' => 10000])->save();
        $this->processo([], 'homologado')->forceFill(['valor_estimado' => 30000, 'valor_homologado' => 24000])->save();
        Capacitacao::create($this->curso(['orgao_id' => $this->orgao->id, 'user_id' => $this->u['agente-contratacao']->id, 'registrada_por' => $this->u['agente-contratacao']->id]));

        $r = $this->actingAs($this->u['autoridade'])->get('/governanca?ano=2026')->assertOk();
        $r->assertViewHas('competicao', fn ($c) => $c['total'] === 2 && $c['diretas'] === 1 && $c['pct_valor'] == 25);
        $r->assertViewHas('economia', fn ($e) => $e['pct'] == 20.0);
        $r->assertViewHas('capacitacao', fn ($c) => $c['capacitados'] === 1 && $c['agentes'] >= 5);
        $r->assertSee('Governança das contratações');
    }

    public function test_governanca_restrita(): void
    {
        $this->actingAs($this->u['requisitante'])->get('/governanca')->assertForbidden();
        $this->actingAs($this->u['auditor'])->get('/governanca')->assertOk();
    }
}
