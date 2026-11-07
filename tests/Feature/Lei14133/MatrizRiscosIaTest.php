<?php

namespace Tests\Feature\Lei14133;

use App\Licitacao\Ia\ClienteIa;
use App\Models\Contrato;
use App\Models\PlanoContratacao;
use App\Models\RiscoProcesso;
use App\Models\UsoIa;
use App\Models\ValorLimite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Matriz de riscos estruturada com proposta da IA (3.2) e recomendações da IA no PCA, nos preços e no contrato (T2). */
class MatrizRiscosIaTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    /** @var list<string> */
    public array $pedidos = [];

    public string $resposta = '';

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

                return ['texto' => $this->teste->resposta, 'tokens_entrada' => 100, 'tokens_saida' => 50];
            }
        });
    }

    public function test_registra_risco_e_exige_tratamento_quando_alto(): void
    {
        $p = $this->processo();
        $quem = $this->u['planejamento'];
        $base = ['risco' => 'Atraso na entrega por falta do produto no mercado', 'probabilidade' => 4, 'impacto' => 4, 'alocacao' => 'contratado'];

        $this->actingAs($quem)->post("/processos/{$p->id}/riscos", $base)->assertSessionHasErrors('risco');
        $this->actingAs($quem)->post("/processos/{$p->id}/riscos", $base + ['tratamento' => 'Multa por atraso e cadastro reserva de fornecedores'])->assertRedirect("/processos/{$p->id}?aba=riscos");
        $this->actingAs($this->u['requisitante'])->post("/processos/{$p->id}/riscos", $base)->assertForbidden();

        $this->actingAs($quem)->get("/processos/{$p->id}?aba=riscos")->assertOk()->assertSee('Alto · 16')->assertSee('Contratado');
        $this->actingAs($quem)->post("/processos/{$p->id}/riscos/peca")->assertRedirect("/processos/{$p->id}/documentos/mapa_riscos");
        $this->actingAs($quem)->get("/processos/{$p->id}/documentos/mapa_riscos")->assertOk()->assertSee('1. Atraso na entrega por falta do produto no mercado', false);
    }

    public function test_ia_propoe_e_o_servidor_aceita_so_o_que_marcar(): void
    {
        $p = $this->processo(['objeto' => 'Aquisição de notebooks para as escolas. Contato: fulano@exemplo.gov.br']);
        $this->resposta = json_encode([
            ['risco' => 'Atraso na entrega dos notebooks', 'causa' => 'Falta de estoque', 'probabilidade' => 3, 'impacto' => 4, 'alocacao' => 'contratado', 'tratamento' => 'Multa diária e prazo de substituição', 'responsavel' => 'Fiscal do contrato'],
            ['risco' => 'Equipamento fora da especificação técnica', 'probabilidade' => 2, 'impacto' => 3, 'alocacao' => 'contratado', 'tratamento' => null, 'responsavel' => null],
            ['risco' => 'x', 'probabilidade' => 1, 'impacto' => 1, 'alocacao' => 'contratado'],
        ], JSON_UNESCAPED_UNICODE);

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/riscos/ia")->assertRedirect()->assertSessionHas('sucesso', fn ($m) => str_starts_with($m, '2 risco(s)'));
        $this->assertStringNotContainsString('fulano@exemplo.gov.br', $this->pedidos[0], 'dado pessoal não vai para a IA');
        $this->assertSame(0, RiscoProcesso::count(), 'nada é gravado sem o servidor aceitar');
        $this->actingAs($this->u['planejamento'])->get("/processos/{$p->id}?aba=riscos")->assertSee('Proposta da NexMind IA')->assertSee('Atraso na entrega dos notebooks');

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/riscos/ia/aceitar", ['escolhidas' => [1]])->assertRedirect();
        $r = RiscoProcesso::sole();
        $this->assertSame(['Equipamento fora da especificação técnica', 'ia'], [$r->risco, $r->origem]);
        $this->assertSame(['mapa_riscos', 'riscos'], [UsoIa::sole()->tipo_documento, UsoIa::sole()->acao]);
    }

    public function test_matriz_obrigatoria_em_obra_de_grande_vulto(): void
    {
        ValorLimite::create(['ano' => 2026, 'dispositivo' => 'art6_XXII', 'valor' => 1000000, 'ato_normativo' => 'Decreto de teste (fictício)']);
        $obra = $this->processo(['categoria' => 7]);
        $obra->forceFill(['valor_estimado' => 2000000])->save();

        $this->assertTrue($obra->matrizDeRiscosObrigatoria());
        $this->assertFalse($this->processo()->matrizDeRiscosObrigatoria());
        $this->actingAs($this->u['planejamento'])->get("/processos/{$obra->id}?aba=riscos")->assertSee('art. 22, § 3º');
    }

    public function test_recomendacoes_da_ia_no_pca_nos_precos_e_no_contrato(): void
    {
        $this->resposta = '- Agrupe os itens de papel (art. 40, V).';
        $plano = PlanoContratacao::create(['orgao_id' => $this->orgao->id, 'ano' => 2027, 'situacao' => 'rascunho']);
        $this->actingAs($this->u['planejamento'])->post("/pca/{$plano->id}/ia")->assertRedirect("/pca/{$plano->id}/consolidacao")->assertSessionHas('recomendacao_ia', $this->resposta);

        $p = $this->processo();
        $this->itemComPreco($p);
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/precos/ia")->assertRedirect("/processos/{$p->id}?aba=itens");
        $this->assertStringContainsString('Fonte painel_oficial', end($this->pedidos));

        $c = Contrato::create([
            'orgao_id' => $this->orgao->id, 'processo_id' => $p->id, 'fornecedor_id' => $this->fornecedor()->id, 'gestor_id' => $this->u['gestor-contrato']->id, 'fiscal_id' => $this->u['fiscal']->id,
            'numero' => '7', 'ano' => 2026, 'tipo' => 1, 'objeto' => 'Limpeza predial (fictícia).', 'categoria' => 8, 'valor_inicial' => 12000, 'valor_global' => 12000,
            'assinatura' => '2026-03-02', 'vigencia_inicio' => '2026-03-02', 'vigencia_fim' => '2026-12-31', 'situacao' => 'vigente',
        ]);
        $this->actingAs($this->u['gestor-contrato'])->post("/contratos/{$c->id}/ia")->assertRedirect("/contratos/{$c->id}")->assertSessionHas('recomendacao_ia');
        $this->assertSame(['pca', 'precos', 'contrato'], UsoIa::orderBy('id')->pluck('tipo_documento')->all());

        $this->actingAs($this->u['requisitante'])->post("/pca/{$plano->id}/ia")->assertForbidden();
        config(['nexplangov.ia.habilitada' => false]);
        $this->actingAs($this->u['planejamento'])->post("/pca/{$plano->id}/ia")->assertSessionHas('erro');
    }
}
