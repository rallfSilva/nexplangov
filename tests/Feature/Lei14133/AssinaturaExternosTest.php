<?php

namespace Tests\Feature\Lei14133;

use App\Licitacao\Fluxo\Assinaturas;
use App\Licitacao\Fluxo\FluxoDocumento;
use App\Models\Assinatura;
use App\Models\Fluxo;
use App\Models\FluxoEtapa;
use App\Models\JuntadaProcesso;
use App\Models\Processo;
use App\Models\SolicitacaoExterna;
use App\Notifications\PedidoExternoEnviado;
use App\Notifications\PedidoExternoRespondido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Assinatura em lote, assinatura por externo, notificação com ciência e pedido de documentos por e-mail. */
class AssinaturaExternosTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->montarCenario();
    }

    /** Processo com as peças indicadas exigindo assinatura, já prontas e aguardando. */
    private function aguardandoAssinatura(array $tipos = ['dfd']): Processo
    {
        $fluxo = Fluxo::create(['orgao_id' => $this->orgao->id, 'nome' => 'Assinatura '.random_int(1, 99999), 'aplicacao' => 'todos', 'padrao' => false]);
        foreach ($tipos as $i => $t) {
            FluxoEtapa::create(['orgao_id' => $this->orgao->id, 'fluxo_id' => $fluxo->id, 'ordem' => $i + 1, 'tipo' => $t, 'depende_de' => [], 'exige_assinatura' => true, 'origem' => 'formulario']);
        }
        $p = $this->processo();
        app(FluxoDocumento::class)->aplicar($p, $fluxo);
        foreach ($tipos as $t) {
            $this->concluirDocumento($p->refresh(), $t, $this->u['planejamento']);
        }

        return $p->refresh();
    }

    private function pedir($p, string $tipo, array $extra = []): string
    {
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/pedidos-externos", $extra + [
            'tipo' => $tipo, 'nome' => 'Marina Duarte Teles', 'email' => 'marina@fornecedora-ficticia.test', 'mensagem' => 'Mensagem de teste para a pessoa de fora.', 'prazo_em' => now()->addDays(5)->toDateString(),
        ])->assertSessionMissing('erro')->assertSessionHasNoErrors();

        $token = null;
        Notification::assertSentTo(new AnonymousNotifiable, PedidoExternoEnviado::class, function ($n, $canais, $notifiable) use (&$token) {
            $token = $n->token;

            return isset($notifiable->routes['mail']['marina@fornecedora-ficticia.test']);
        });

        return $token;
    }

    public function test_assinatura_em_lote_com_uma_senha(): void
    {
        $p = $this->aguardandoAssinatura(['dfd', 'termo_referencia']);
        $docs = $p->documentos()->where('situacao', 'aguardando_assinatura')->pluck('id')->all();
        $this->assertCount(2, $docs);

        $this->actingAs($this->u['planejamento'])->get('/assinaturas/pendentes')->assertOk()->assertSee('Termo de referência')->assertSee($p->identificacao());

        $this->actingAs($this->u['planejamento'])->post('/assinaturas/lote', ['documentos' => $docs, 'senha' => 'errada'])->assertSessionHas('erro');
        $this->assertSame(0, Assinatura::count());

        $this->actingAs($this->u['planejamento'])->post('/assinaturas/lote', ['documentos' => $docs, 'senha' => 'password'])->assertSessionHas('sucesso', '2 peça(s) assinada(s).');
        $this->assertSame(2, Assinatura::count());
        $this->assertSame(0, $p->documentos()->where('situacao', 'aguardando_assinatura')->count());
    }

    public function test_lote_ignora_pecas_de_que_a_pessoa_nao_e_signataria(): void
    {
        $p = $this->aguardandoAssinatura();
        $doc = $p->documentoAtual('dfd');

        $this->actingAs($this->u['agente-contratacao'])->post('/assinaturas/lote', ['documentos' => [$doc->id], 'senha' => 'password'])->assertSessionHas('sucesso', '0 peça(s) assinada(s).');
        $this->assertSame(0, Assinatura::count());
    }

    public function test_externo_assina_pelo_link_e_a_peca_so_conclui_depois(): void
    {
        $p = $this->aguardandoAssinatura();
        $doc = $p->documentoAtual('dfd');
        $token = $this->pedir($p, 'assinatura', ['documento_id' => $doc->id]);

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/documentos/dfd/assinar", ['senha' => 'password']);
        $this->assertSame('aguardando_assinatura', $doc->fresh()->situacao, 'falta o externo');

        auth()->logout();
        $this->get("/externo/{$token}")->assertOk()->assertSee('Assinatura de peça')->assertSee('Documento de formalização da demanda');
        $this->post("/externo/{$token}", ['nome' => 'Outra Pessoa', 'ciente' => '1'])->assertSessionHas('erro');
        $this->post("/externo/{$token}", ['nome' => 'marina duarte teles', 'ciente' => '1'])->assertSessionHas('sucesso');

        $this->assertSame('concluido', $doc->fresh()->situacao);
        $ext = Assinatura::whereNull('user_id')->firstOrFail();
        $this->assertSame('Marina Duarte Teles', $ext->nome);
        $this->assertTrue(app(Assinaturas::class)->verificar($ext)['valida']);
        $this->get('/validar/'.$ext->codigo)->assertOk()->assertSee('Assinatura válida')->assertSee('Marina Duarte Teles');
        Notification::assertSentTo($this->u['planejamento'], PedidoExternoRespondido::class);

        $this->post("/externo/{$token}", ['nome' => 'Marina Duarte Teles', 'ciente' => '1'])->assertSessionHas('erro');
        $this->assertSame(2, Assinatura::count());
    }

    public function test_pedido_de_documentos_vira_juntada_nos_autos(): void
    {
        $p = $this->processo();
        $token = $this->pedir($p, 'documentos');

        auth()->logout();
        $this->post("/externo/{$token}", ['arquivos' => [UploadedFile::fake()->create('certidao.pdf', 20, 'application/pdf'), UploadedFile::fake()->create('balanco.pdf', 20, 'application/pdf')], 'observacao' => 'Seguem as certidões.'])
            ->assertSessionHas('sucesso');

        $this->assertSame(2, JuntadaProcesso::where('processo_id', $p->id)->where('origem', 'externo')->count());
        $j = JuntadaProcesso::firstOrFail();
        $this->assertSame('Marina Duarte Teles', $j->autor_externo);
        $this->assertNotNull(SolicitacaoExterna::firstOrFail()->respondida_em);

        $this->actingAs($this->u['planejamento'])->get("/processos/{$p->id}?aba=autos")->assertSee('Documento enviado por Marina Duarte Teles')->assertSee('Documentos recebidos');
    }

    public function test_notificacao_com_ciencia_prazo_e_cancelamento(): void
    {
        $p = $this->processo();
        $token = $this->pedir($p, 'notificacao');
        $s = SolicitacaoExterna::firstOrFail();
        $this->assertNotSame($token, $s->token_hash, 'o banco guarda só o hash do link');

        $this->travel(6)->days();
        auth()->logout();
        $this->get("/externo/{$token}")->assertOk()->assertSee('O prazo deste pedido terminou');
        $this->post("/externo/{$token}", ['ciente' => '1'])->assertSessionHas('erro');
        $this->travelBack();

        $this->post("/externo/{$token}", ['ciente' => '1'])->assertSessionHas('sucesso');
        $this->assertNotNull($s->fresh()->respondida_em);

        $token2 = $this->pedir($p, 'notificacao');
        $s2 = SolicitacaoExterna::latest('id')->first();
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/pedidos-externos/{$s2->id}/cancelar")->assertSessionMissing('erro');
        auth()->logout();
        $this->get("/externo/{$token2}")->assertSee('cancelado');
        $this->get('/externo/'.str_repeat('a', 48))->assertNotFound();
    }

    public function test_quem_so_consulta_nao_envia_pedido_externo(): void
    {
        $p = $this->processo();
        $this->actingAs($this->u['auditor'])->post("/processos/{$p->id}/pedidos-externos", ['tipo' => 'notificacao', 'nome' => 'X', 'email' => 'x@x.test', 'mensagem' => 'Mensagem de teste.', 'prazo_em' => now()->addDay()->toDateString()])->assertForbidden();
        Notification::assertNothingSent();
    }
}
