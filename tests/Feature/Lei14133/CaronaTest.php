<?php

namespace Tests\Feature\Lei14133;

use App\Models\Ata;
use App\Models\AtaAdesao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Carona: pedido público com protocolo, aceite do fornecedor, vedação federal, prazo e autorização com QR Code (art. 86). */
class CaronaTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    private Ata $ata;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->montarCenario();
        $this->travelTo('2026-11-03 10:00:00');

        $p = $this->processo(['srp' => true], 'homologado');
        $f = $this->fornecedor('99.000.333/0001-88');
        $p->itens()->create([
            'orgao_id' => $p->orgao_id, 'numero' => 1, 'material_servico' => 'M', 'descricao' => 'Resma de papel A4 (fictícia)', 'unidade_medida' => 'UN', 'quantidade' => 1000,
            'tipo_beneficio' => 4, 'situacao' => 'homologado', 'fornecedor_vencedor_id' => $f->id, 'valor_unitario_homologado' => 25,
        ]);
        $this->actingAs($this->u['gestor-contrato'])->post('/atas', ['processo_id' => $p->id, 'assinatura' => '2026-11-03', 'vigencia_inicio' => '2026-11-03', 'vigencia_fim' => '2027-11-02'])->assertSessionHas('sucesso');
        $this->ata = Ata::firstOrFail();
        Auth::logout();
    }

    private function pedidoPublico(array $mudar = []): TestResponse
    {
        return $this->post("/carona/atas/{$this->ata->id}/pedidos", $mudar + [
            'item_id' => $this->ata->itens()->first()->id, 'aderente_nome' => 'Prefeitura de Vale Verde (fictícia)', 'aderente_cnpj' => '99.000.555/0001-09',
            'aderente_esfera' => 'municipal', 'aderente_uf' => 'rr', 'aderente_municipio' => 'Vale Verde', 'solicitante_nome' => 'Servidora Fictícia',
            'solicitante_cargo' => 'Agente de contratação', 'solicitante_email' => 'compras@valeverde.example', 'quantidade' => '300',
            'justificativa' => 'Reposição do estoque de papel das escolas municipais.', 'vantagem' => 'Preço 15% abaixo da média do painel de preços (art. 23).',
            'oficio' => UploadedFile::fake()->create('oficio.pdf', 50, 'application/pdf'),
        ]);
    }

    public function test_orgao_de_fora_pede_sem_conta_e_acompanha_pelo_protocolo(): void
    {
        $this->get('/carona')->assertOk()->assertSee('Ata '.$this->ata->identificacao());
        $this->get("/carona/atas/{$this->ata->id}")->assertOk()->assertSee('Resma de papel A4');

        $this->pedidoPublico()->assertRedirect('/carona/consulta')->assertSessionHas('protocolo');
        $a = AtaAdesao::firstOrFail();
        $this->assertSame('publico', $a->origem);
        $this->assertSame('RR', $a->aderente_uf);
        $this->assertMatchesRegularExpression('/^ADS-2026-\d{6}$/', $a->protocolo);
        Storage::disk('local')->assertExists($a->oficio_caminho);
        $this->assertSame(64, strlen($a->oficio_hash));

        // O CNPJ errado não revela o pedido.
        $this->post('/carona/consulta', ['protocolo' => $a->protocolo, 'cnpj' => '99.000.666/0001-07'])->assertSessionHas('erro');
        $this->post('/carona/consulta', ['protocolo' => $a->protocolo, 'cnpj' => '99000555000109'])->assertOk()->assertSee('Aguardando aceite do fornecedor');
    }

    public function test_pedido_publico_exige_oficio_e_respeita_o_limite_de_50_por_cento(): void
    {
        $this->pedidoPublico(['oficio' => null])->assertSessionHasErrors('oficio');
        $this->pedidoPublico(['quantidade' => '501'])->assertSessionHas('motivos');
        $this->assertSame(0, AtaAdesao::count());
    }

    public function test_orgao_federal_nao_adere_a_ata_municipal(): void
    {
        $this->pedidoPublico(['aderente_esfera' => 'federal'])->assertSessionHas('motivos', fn ($m) => str_contains(implode(' ', $m), 'art. 86, § 8º'));
        $this->assertSame(0, AtaAdesao::count());
    }

    public function test_fornecedor_responde_pelo_link_assinado_e_so_entao_o_gerenciador_autoriza(): void
    {
        $this->pedidoPublico();
        $a = AtaAdesao::firstOrFail();
        $g = $this->u['gestor-contrato'];

        // Sem o aceite, não autoriza.
        $this->actingAs($g)->post("/atas/{$this->ata->id}/adesoes/{$a->id}/decidir", ['acao' => 'autorizar'])->assertSessionHas('motivos', fn ($m) => str_contains(implode(' ', $m), '§ 2º, III'));
        Auth::logout();

        // Link sem assinatura é recusado; com assinatura, o fornecedor responde uma vez.
        $this->get("/carona/aceite/{$a->id}")->assertForbidden();
        $link = URL::temporarySignedRoute('carona.aceite', now()->addDays(15), ['adesao' => $a->id]);
        $this->get($link)->assertOk()->assertSee($a->protocolo);
        $this->post($link, ['resposta' => 'aceito', 'representante' => 'Representante Fictício, sócio-administrador', 'ciente' => '1'])->assertSessionHas('sucesso');
        $this->post($link, ['resposta' => 'recusado', 'representante' => 'Outra pessoa fictícia', 'obs' => 'Mudou de ideia', 'ciente' => '1'])->assertSessionHas('motivos');
        $this->assertSame('aceito', $a->refresh()->fornecedor_aceite);
        $this->assertSame('link', $a->fornecedor_aceite_via);

        $this->actingAs($g)->post("/atas/{$this->ata->id}/adesoes/{$a->id}/decidir", ['acao' => 'autorizar'])->assertSessionHas('sucesso');
        $a->refresh();
        $this->assertSame('autorizada', $a->situacao);
        $this->assertSame('2027-02-01', $a->prazo_contratacao->toDateString()); // 90 dias corridos
        Storage::disk('local')->assertExists($a->autorizacao_caminho);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($a->autorizacao_caminho)), $a->autorizacao_hash);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($a->autorizacao_caminho));

        $this->get("/atas/{$this->ata->id}/adesoes/{$a->id}/arquivos/autorizacao")->assertOk();
        Auth::logout();

        // Verificação pública pelo código do QR Code.
        $this->get("/carona/verificar/{$a->codigo_verificacao}")->assertOk()->assertSee('Autorização válida')->assertSee($a->autorizacao_hash);
        $this->get("/carona/verificar/{$a->codigo_verificacao}/pdf")->assertOk();
        $this->get('/carona/verificar/NAO-EXISTE-0000')->assertOk()->assertSee('Nenhuma autorização válida');
    }

    public function test_recusa_do_fornecedor_registrada_com_documento_impede_a_autorizacao(): void
    {
        $this->pedidoPublico();
        $a = AtaAdesao::firstOrFail();
        $g = $this->u['gestor-contrato'];

        $this->actingAs($g)->post("/atas/{$this->ata->id}/adesoes/{$a->id}/aceite", ['resposta' => 'recusado', 'representante' => 'Representante Fictício', 'obs' => 'Sem capacidade de entrega'])->assertSessionHasErrors('documento');
        $this->actingAs($g)->post("/atas/{$this->ata->id}/adesoes/{$a->id}/aceite", [
            'resposta' => 'recusado', 'representante' => 'Representante Fictício', 'obs' => 'Sem capacidade de entrega', 'documento' => UploadedFile::fake()->create('resposta.pdf', 20, 'application/pdf'),
        ])->assertSessionHas('sucesso');

        $this->actingAs($g)->post("/atas/{$this->ata->id}/adesoes/{$a->id}/decidir", ['acao' => 'autorizar'])->assertSessionHas('motivos');
        $this->actingAs($g)->post("/atas/{$this->ata->id}/adesoes/{$a->id}/decidir", ['acao' => 'negar', 'motivo' => 'Fornecedor recusou o fornecimento.'])->assertSessionHas('sucesso');
        $this->assertSame('negada', $a->refresh()->situacao);
    }

    public function test_consumo_do_aderente_para_depois_do_prazo_para_contratar(): void
    {
        $this->pedidoPublico();
        $a = AtaAdesao::firstOrFail();
        $a->forceFill(['fornecedor_aceite' => 'aceito', 'fornecedor_aceite_em' => now(), 'fornecedor_aceite_representante' => 'Representante Fictício', 'fornecedor_aceite_via' => 'registro'])->save();
        $g = $this->u['gestor-contrato'];
        $ai = $this->ata->itens()->first();
        $this->actingAs($g)->post("/atas/{$this->ata->id}/adesoes/{$a->id}/decidir", ['acao' => 'autorizar'])->assertSessionHas('sucesso');

        $this->travelTo('2027-02-02 09:00:00');
        $this->actingAs($g)->post("/atas/{$this->ata->id}/itens/{$ai->id}/consumos", ['quantidade' => '10', 'referencia' => 'Contrato 1/2027 do aderente', 'adesao_id' => $a->id])
            ->assertSessionHas('motivos', fn ($m) => str_contains(implode(' ', $m), 'prazo'));
        $this->assertSame('Prazo para contratar encerrado', $a->refresh()->situacaoExibida());
    }

    public function test_tela_da_ata_mostra_o_link_de_aceite_para_o_fornecedor(): void
    {
        $this->pedidoPublico();
        $this->actingAs($this->u['gestor-contrato'])->get("/atas/{$this->ata->id}")->assertOk()->assertSee('Link para o fornecedor responder')->assertSee('signature=', false);
    }
}
