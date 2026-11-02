<?php

namespace Tests\Feature\Lei14133;

use App\Models\DocumentoProcesso;
use App\Models\EnvioPncp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

/** Fluxo completo do pregão: instrução (art. 18), parecer (art. 53), autorização e publicação no PNCP. */
class FluxoProcessoTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        config(['nexplangov.pncp.login' => 'plataforma', 'nexplangov.pncp.senha' => 'segredo-de-teste-123']);
    }

    public function test_nao_vai_ao_juridico_sem_instrucao_completa(): void
    {
        $p = $this->processo();

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/tramitar", ['destino' => 'analise_juridica'])
            ->assertRedirect()
            ->assertSessionHas('motivos', fn ($m) => collect($m)->contains(fn ($x) => str_contains($x, 'Estudo técnico preliminar')));

        $this->assertSame('planejamento', $p->fresh()->situacao);
    }

    public function test_etp_sem_incisos_obrigatorios_nao_conclui(): void
    {
        $p = $this->processo();

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/documentos/etp", ['conteudo' => ['i_necessidade' => 'Só a necessidade'], 'acao' => 'concluir'])
            ->assertSessionHas('erro');

        $this->assertFalse($p->documentoConcluido('etp'));
    }

    public function test_fluxo_completo_ate_publicacao_no_pncp(): void
    {
        Http::fake([
            '*/v1/usuarios/login' => Http::response(null, 200, ['Authorization' => 'Bearer token-teste']),
            '*/v1/orgaos/11222333000181/compras' => Http::response(['ok' => true], 201, ['location' => 'https://treina.pncp.gov.br/api/pncp/v1/orgaos/11222333000181/compras/2026/7']),
        ]);

        $p = $this->processo();
        $this->itemComPreco($p, 10.0, 100);
        $this->assertEquals(1000.0, (float) $p->fresh()->valor_estimado); // mediana de 9,50 / 10,00 / 10,50

        foreach (['dfd', 'etp', 'mapa_riscos', 'termo_referencia', 'dotacao', 'minuta_edital', 'minuta_contrato'] as $tipo) {
            $this->concluirDocumento($p, $tipo, $this->u['planejamento']);
        }

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/tramitar", ['destino' => 'analise_juridica'])->assertSessionHas('sucesso');
        $this->assertSame('analise_juridica', $p->fresh()->situacao);

        // Planejamento não emite parecer; o jurídico sim.
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/documentos/parecer_juridico", ['conteudo' => ['analise' => 'x', 'conclusao' => 'favorável'], 'acao' => 'concluir'])->assertForbidden();
        $this->concluirDocumento($p, 'parecer_juridico', $this->u['juridico'], ['conclusao' => 'Favorável, sem ressalvas.']);
        $this->actingAs($this->u['juridico'])->post("/processos/{$p->id}/tramitar", ['destino' => 'aguardando_autorizacao'])->assertSessionHas('sucesso');

        // Só a autoridade autoriza.
        $this->actingAs($this->u['agente-contratacao'])->post("/processos/{$p->id}/tramitar", ['destino' => 'publicado'])->assertSessionHas('motivos');
        $this->concluirDocumento($p, 'autorizacao', $this->u['autoridade']);
        $this->actingAs($this->u['autoridade'])->post("/processos/{$p->id}/tramitar", ['destino' => 'publicado'])->assertSessionHas('sucesso');

        $p->refresh();
        $this->assertSame('publicado', $p->situacao);
        $this->assertSame('2026/7', $p->numero_controle_pncp);

        $envio = EnvioPncp::firstOrFail();
        $this->assertSame('publicado', $envio->estado);
        $this->assertSame(6, $envio->payload['modalidadeId']);
        $this->assertSame(1, $envio->payload['tipoInstrumentoConvocatorioId']);
        $this->assertCount(1, $envio->payload['itensCompra']);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer token-teste') && str_ends_with($r->url(), '/compras'));

        $this->assertCount(3, $p->eventos);
    }

    public function test_revogacao_exige_justificativa_e_retifica_no_pncp(): void
    {
        Http::fake([
            '*/v1/usuarios/login' => Http::response(null, 200, ['Authorization' => 'Bearer t']),
            '*/compras/2026/9' => Http::response([], 200),
        ]);
        $p = $this->processo([], 'publicado');
        $p->forceFill(['numero_controle_pncp' => '2026/9'])->save();
        $this->itemComPrecoDireto($p);

        $this->actingAs($this->u['autoridade'])->post("/processos/{$p->id}/tramitar", ['destino' => 'revogado', 'justificativa' => 'curta'])->assertSessionHas('motivos');
        $this->actingAs($this->u['autoridade'])->post("/processos/{$p->id}/tramitar", ['destino' => 'revogado', 'justificativa' => 'Fato superveniente: a demanda deixou de existir com a nova lei municipal.'])->assertSessionHas('sucesso');

        $envio = EnvioPncp::firstOrFail();
        $this->assertSame('retificar', $envio->operacao);
        $this->assertSame('PUT', $envio->metodo);
        $this->assertSame(2, $envio->payload['situacaoCompraId']);
        $this->assertSame('publicado', $envio->estado);
    }

    public function test_pncp_recusa_422_vira_erro_sem_nova_tentativa(): void
    {
        Http::fake([
            '*/v1/usuarios/login' => Http::response(null, 200, ['Authorization' => 'Bearer t']),
            '*/compras' => Http::response(['message' => 'Unidade compradora inexistente'], 422),
        ]);
        $p = $this->processo([], 'aguardando_autorizacao');
        $this->itemComPrecoDireto($p);
        $this->concluirDocumento($p, 'autorizacao', $this->u['autoridade']);

        $this->actingAs($this->u['autoridade'])->post("/processos/{$p->id}/tramitar", ['destino' => 'publicado'])->assertSessionHas('sucesso');

        $envio = EnvioPncp::firstOrFail();
        $this->assertSame('erro', $envio->estado);
        $this->assertStringContainsString('Unidade compradora inexistente', $envio->erro);
        $this->assertNull($p->fresh()->numero_controle_pncp);
    }

    public function test_documento_concluido_nao_muda_e_nova_edicao_gera_versao(): void
    {
        $p = $this->processo();
        $this->concluirDocumento($p, 'dfd', $this->u['planejamento']);
        $doc = DocumentoProcesso::firstOrFail();
        $this->assertNotNull($doc->hash_conteudo);

        $this->expectException(LogicException::class);
        $doc->update(['conteudo' => ['necessidade' => 'alterado']]);
    }

    public function test_nova_versao_apos_conclusao(): void
    {
        $p = $this->processo();
        $this->concluirDocumento($p, 'dfd', $this->u['planejamento']);
        $this->concluirDocumento($p, 'dfd', $this->u['planejamento'], ['necessidade' => 'Versão revista.']);

        $this->assertSame(2, $p->documentoAtual('dfd')->versao);
        $this->assertSame(2, $p->documentos()->where('tipo', 'dfd')->count());
    }

    private function itemComPrecoDireto($p): void
    {
        $p->itens()->create(['orgao_id' => $p->orgao_id, 'numero' => 1, 'material_servico' => 'M', 'descricao' => 'Item', 'unidade_medida' => 'UN', 'quantidade' => 10])
            ->forceFill(['valor_unitario_estimado' => 5, 'valor_total_estimado' => 50])->save();
    }
}
