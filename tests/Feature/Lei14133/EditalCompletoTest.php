<?php

namespace Tests\Feature\Lei14133;

use App\Licitacao\EditalCompleto;
use App\Licitacao\Ia\ClienteIa;
use App\Models\ModeloDocumento;
use App\Models\Processo;
use App\Models\UsoIa;
use Database\Seeders\ModelosPadrao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Gerar edital completo" na minuta do edital e caderno do edital com os anexos (art. 25). */
class EditalCompletoTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    /** @var list<string> */
    public array $pedidos = [];

    public string $resposta = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->orgao->update(['municipio' => 'Vale Verde', 'uf' => 'RR']);
        ModelosPadrao::instalarPara($this->orgao);
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

    private function processoComTr(): Processo
    {
        $p = $this->processo(['abertura_propostas_em' => '2026-12-15 09:00:00']);
        $this->itemComPreco($p);
        $p->itens()->first()->update(['tipo_beneficio' => 1]);
        $this->concluirDocumento($p, 'termo_referencia', $this->u['planejamento'], ['h_selecao' => 'Atestado de capacidade técnica de fornecimento compatível com o objeto.']);

        return $p;
    }

    public function test_gera_o_edital_na_tela_sem_salvar(): void
    {
        $p = $this->processoComTr();
        $quem = $this->u['planejamento'];
        $modelo = ModeloDocumento::where('codigo', 'edital-pregao-eletronico')->sole();

        $this->actingAs($quem)->get("/processos/{$p->id}/documentos/minuta_edital")->assertOk()
            ->assertSee('Gerar edital completo')->assertSee('2026-12-15T09:00', false)->assertSee('Atestado de capacidade técnica de fornecimento');

        $this->actingAs($quem)->post("/processos/{$p->id}/edital/gerar", ['modelo_id' => $modelo->id, 'edital' => [
            'sessao_em' => '2026-12-15T09:00', 'local' => 'Compras.gov.br', 'prazo_assinatura' => '5', 'garantia_pct' => '0',
            'habilitacao' => 'Atestado de capacidade técnica de fornecimento compatível com o objeto.',
        ]])->assertRedirect("/processos/{$p->id}/documentos/minuta_edital");

        $this->assertNull($p->documentoAtual('minuta_edital'), 'nada é gravado sem o servidor salvar');
        $tela = $this->actingAs($quem)->get("/processos/{$p->id}/documentos/minuta_edital")->assertOk();
        $tela->assertSee('Edital montado a partir do modelo')->assertSee('Nenhum trecho ficou entre colchetes')
            ->assertSee('em 15/12/2026, às 09h00')->assertSee('participação exclusiva de microempresas')->assertSee('Vale Verde/RR')
            ->assertSee('Divulgue o edital no PNCP até 03/12/2026');
        $this->assertStringNotContainsString('[informe', (string) session()->getOldInput('conteudo.texto'));
    }

    public function test_quem_nao_elabora_nao_gera(): void
    {
        $p = $this->processoComTr();
        $modelo = ModeloDocumento::where('codigo', 'edital-pregao-eletronico')->sole();

        $this->actingAs($this->u['requisitante'])->post("/processos/{$p->id}/edital/gerar", ['modelo_id' => $modelo->id])->assertForbidden();
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/edital/gerar", ['modelo_id' => $modelo->id, 'edital' => ['prazo_assinatura' => 90]])->assertSessionHasErrors('edital.prazo_assinatura');
    }

    public function test_ia_propoe_so_o_que_passa_nas_regras(): void
    {
        config(['nexplangov.ia.habilitada' => true]);
        $p = $this->processoComTr();
        $this->resposta = '{"habilitacao": "Atestado de fornecimento de 50% do quantitativo.", "prazo_assinatura": 5, "garantia_pct": 9, "garantia_justificativa": null}';

        $r = $this->actingAs($this->u['planejamento'])->postJson("/processos/{$p->id}/edital/ia", ['edital' => ['local' => 'Compras.gov.br']])->assertOk();
        $this->assertSame(['habilitacao' => 'Atestado de fornecimento de 50% do quantitativo.', 'prazo_assinatura' => 5], $r->json('proposta'), 'garantia de 9% sem justificativa é descartada');
        $this->assertStringContainsString('Compras.gov.br', $this->pedidos[0]);
        $this->assertSame(['minuta_edital', 'edital'], [UsoIa::sole()->tipo_documento, UsoIa::sole()->acao]);
        $this->assertNull($p->documentoAtual('minuta_edital'));
    }

    public function test_caderno_pdf_junta_edital_e_anexos(): void
    {
        $p = $this->processoComTr();
        $this->concluirDocumento($p, 'minuta_edital', $this->u['planejamento'], ['texto' => "EDITAL DE PREGÃO ELETRÔNICO\n8.1. Assinar em até [informe] dias úteis."]);

        $r = $this->actingAs($this->u['auditor'])->get("/processos/{$p->id}/edital/caderno.pdf")->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());

        $c = app(EditalCompleto::class)->caderno($p);
        $this->assertContains('Edital: completar [informe].', $c['pendencias']);
        $this->assertContains('Anexo II, Minuta do Contrato: peça ainda não elaborada.', $c['pendencias']);
        $this->assertNotContains('Anexo I, Termo de Referência: peça ainda não elaborada.', $c['pendencias']);
        $this->assertSame(['I', 'II', 'III', 'IV'], array_column($c['anexos'], 'numero'));
    }
}
