<?php

namespace Tests\Feature\Lei14133;

use App\Models\Processo;
use App\Models\TramiteProcesso;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Remessa entre unidades, recebimento, cancelamento, arquivamento, permanência e "Meus processos". */
class TramitacaoUnidadesTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    private Unidade $juridica;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->juridica = Unidade::factory()->for($this->orgao)->create(['sigla' => 'PGM', 'nome' => 'Procuradoria (fictícia)']);
        $this->u['planejamento']->update(['unidade_id' => $this->unidade->id]);
        $this->u['juridico']->update(['unidade_id' => $this->juridica->id]);
    }

    private function enviar(Processo $p, int $destino, string $despacho = 'Encaminho para análise e parecer (art. 53).')
    {
        return $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/tramites", ['unidade_destino_id' => $destino, 'despacho' => $despacho]);
    }

    public function test_processo_nasce_na_unidade_e_vai_para_outra_so_depois_de_recebido(): void
    {
        $p = $this->processo();
        $this->assertSame($this->unidade->id, $p->unidade_atual_id);

        $this->enviar($p, $this->juridica->id)->assertSessionHasNoErrors()->assertSessionMissing('erro');
        $t = TramiteProcesso::firstOrFail();
        $this->assertTrue($t->pendente());
        $this->assertSame($this->unidade->id, $p->fresh()->unidade_atual_id, 'em trânsito o processo ainda não chegou');

        $this->actingAs($this->u['juridico'])->get('/meus-processos')->assertOk()->assertSee($p->identificacao())->assertSee('Receber');

        $this->actingAs($this->u['juridico'])->post("/processos/{$p->id}/tramites/{$t->id}/receber")->assertSessionMissing('erro');
        $this->assertSame($this->juridica->id, $p->fresh()->unidade_atual_id);
        $this->assertSame($this->u['juridico']->id, $t->fresh()->recebido_por);

        $this->actingAs($this->u['juridico'])->get("/processos/{$p->id}?aba=tramitacao")->assertOk()
            ->assertSee('Histórico de tramitação')->assertSee('SEMAD → PGM')->assertSee('Tempo de permanência');
    }

    public function test_so_a_unidade_de_destino_recebe_e_so_a_atual_envia(): void
    {
        $p = $this->processo();
        $this->enviar($p, $this->juridica->id);
        $t = TramiteProcesso::firstOrFail();

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/tramites/{$t->id}/receber")->assertSessionHas('erro');
        $this->assertNull($t->fresh()->recebido_em);

        $this->enviar($p, $this->juridica->id)->assertSessionHas('erro');
        $this->assertSame(1, TramiteProcesso::count(), 'não se envia de novo com remessa pendente');

        $q = $this->processo();
        $this->actingAs($this->u['juridico'])->post("/processos/{$q->id}/tramites", ['unidade_destino_id' => $this->unidade->id, 'despacho' => 'Devolvo para ajustes no termo.'])->assertSessionHas('erro');
    }

    public function test_cancelar_remessa_antes_do_recebimento(): void
    {
        $p = $this->processo();
        $this->enviar($p, $this->juridica->id);
        $t = TramiteProcesso::firstOrFail();

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/tramites/{$t->id}/cancelar", ['motivo' => 'Faltou anexar a pesquisa de preços.'])->assertSessionMissing('erro');
        $this->assertNotNull($t->fresh()->cancelado_em);
        $this->assertNull($p->fresh()->remessaPendente());

        $this->enviar($p, $this->juridica->id);
        $t2 = TramiteProcesso::latest('id')->first();
        $this->actingAs($this->u['juridico'])->post("/processos/{$p->id}/tramites/{$t2->id}/receber");
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/tramites/{$t2->id}/cancelar", ['motivo' => 'Tarde demais para cancelar.'])->assertSessionHas('erro');
        $this->assertNull($t2->fresh()->cancelado_em);
    }

    public function test_arquivar_so_encerrado_e_desarquivar_com_motivo(): void
    {
        $aberto = $this->processo();
        $this->actingAs($this->u['planejamento'])->post("/processos/{$aberto->id}/arquivar", ['despacho' => 'Arquivo por conclusão.'])->assertSessionHas('erro');

        $p = $this->processo([], 'contratado');
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/arquivar", ['despacho' => 'Contrato assinado e publicado; arquivo.'])->assertSessionMissing('erro');
        $this->assertTrue($p->fresh()->arquivado());
        $this->assertSame('contratado', $p->fresh()->situacao, 'arquivar não muda a situação do processo');

        $this->enviar($p, $this->juridica->id)->assertSessionHas('erro');
        $this->actingAs($this->u['planejamento'])->get('/meus-processos')->assertOk()->assertSee('Arquivados na unidade')->assertSee($p->identificacao());

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/desarquivar", ['motivo' => 'Pedido de vista do controle interno.'])->assertSessionMissing('erro');
        $this->assertFalse($p->fresh()->arquivado());
        $this->assertSame(['arquivamento', 'desarquivamento'], TramiteProcesso::orderBy('id')->pluck('tipo')->all());
    }

    public function test_quem_nao_tramita_nao_ve_a_caixa(): void
    {
        $this->actingAs($this->u['requisitante'])->get('/meus-processos')->assertForbidden();
    }

    public function test_lotacao_do_usuario_precisa_ser_do_mesmo_orgao(): void
    {
        $admin = $this->usuario('administrador-orgao', $this->orgao);
        $outro = Unidade::factory()->create(['sigla' => 'OUT']);

        $this->actingAs($admin)->put("/usuarios/{$this->u['auditor']->id}", ['name' => 'Auditora Teste', 'email' => $this->u['auditor']->email, 'perfil' => 'auditor', 'unidade_id' => $outro->id])
            ->assertSessionHasErrors('unidade_id');
        $this->actingAs($admin)->put("/usuarios/{$this->u['auditor']->id}", ['name' => 'Auditora Teste', 'email' => $this->u['auditor']->email, 'perfil' => 'auditor', 'unidade_id' => $this->juridica->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->juridica->id, $this->u['auditor']->fresh()->unidade_id);
    }
}
