<?php

namespace Tests\Feature\Lei14133;

use App\Jobs\EnviarAoPncp;
use App\Licitacao\Ia\AssistenteIa;
use App\Models\EnvioPncp;
use App\Models\Orgao;
use App\Models\Processo;
use App\Models\User;
use App\Pncp\FilaPncp;
use App\Pncp\PncpCliente;
use App\Support\TurmaTreinamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TreinamentoTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        config(['nexplangov.pncp.login' => 'plataforma', 'nexplangov.pncp.senha' => 'segredo-de-teste-123']);
    }

    private function turma(string $nome = '01'): array
    {
        return app(TurmaTreinamento::class)->preparar($nome, 30);
    }

    public function test_preparar_cria_orgao_isolado_com_um_acesso_por_perfil_e_dados_ficticios(): void
    {
        $r = $this->turma();
        $o = $r['orgao'];

        $this->assertTrue($o->treinamento);
        $this->assertSame('TREINO01', $o->sigla);
        $this->assertStringContainsString('fictício', $o->nome);
        $this->assertSame(today()->addDays(30)->toDateString(), $o->treinamento_expira_em->toDateString());
        $this->assertCount(9, $r['acessos']);
        foreach ($r['acessos'] as $a) {
            $this->assertStringEndsWith('@treinamento.nexplangov.local', $a['email']);
            $this->assertMatchesRegularExpression('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{14}$/', $a['senha']);
        }
        $this->assertGreaterThan(0, Processo::withoutGlobalScopes()->where('orgao_id', $o->id)->count());

        // Quem é da turma não vê o órgão real, e vice-versa.
        $aluno = User::where('email', $r['acessos'][2]['email'])->first();
        $this->actingAs($aluno)->get(route('processos.index'))->assertOk()->assertSee('Ambiente de treinamento')->assertDontSee('PMX');
        $real = Processo::withoutGlobalScopes()->where('orgao_id', $this->orgao->id)->count();
        $this->actingAs($this->u['planejamento']);
        $this->assertSame($real, Processo::count());
    }

    public function test_turma_nunca_publica_no_pncp(): void
    {
        Http::fake();
        $o = $this->turma()['orgao'];
        $processo = Processo::withoutGlobalScopes()->where('orgao_id', $o->id)->firstOrFail();

        $envio = app(FilaPncp::class)->agendar($processo, 'compra', 'inserir');

        $this->assertContains($envio->estado, ['simulado', 'erro']);
        $this->assertSame('treinamento', $envio->ambiente);
        Http::assertNothingSent();
        $this->assertNull($processo->fresh()->numero_controle_pncp);
    }

    public function test_job_ignora_envio_de_treinamento_mesmo_se_estiver_pendente(): void
    {
        Http::fake();
        $o = $this->turma()['orgao'];
        $p = Processo::withoutGlobalScopes()->where('orgao_id', $o->id)->firstOrFail();
        $e = EnvioPncp::create(['orgao_id' => $o->id, 'publicavel_type' => $p->getMorphClass(), 'publicavel_id' => $p->id, 'recurso' => 'compra', 'operacao' => 'inserir', 'estado' => 'pendente', 'ambiente' => 'treinamento', 'payload' => [], 'metodo' => 'POST', 'rota' => '/x']);

        (new EnviarAoPncp($e->id))->handle(app(PncpCliente::class));

        Http::assertNothingSent();
        $this->assertSame('pendente', $e->fresh()->estado);
    }

    public function test_admin_da_turma_nao_precisa_de_duas_etapas_e_admin_real_continua_precisando(): void
    {
        $r = $this->turma();
        $admin = User::where('email', $r['acessos'][0]['email'])->first();

        $this->assertFalse($admin->exigeDoisFatores());
        $this->assertTrue(User::factory()->comPerfil('administrador-orgao')->create(['orgao_id' => $this->orgao->id])->exigeDoisFatores());
        $this->actingAs($admin)->get(route('usuarios.index'))->assertOk();
    }

    public function test_acesso_acaba_no_fim_do_prazo_e_encerrar_desativa_sem_apagar(): void
    {
        $r = $this->turma();
        $o = $r['orgao'];
        $aluno = User::where('email', $r['acessos'][3]['email'])->first();

        $o->update(['treinamento_expira_em' => today()->subDay()]);
        $this->actingAs($aluno)->get(route('painel'))->assertRedirect(route('login'));

        $this->assertSame(1, app(TurmaTreinamento::class)->encerrarVencidas());
        $this->assertFalse($o->fresh()->ativo);
        $this->assertSame(0, User::where('orgao_id', $o->id)->where('ativo', true)->count());
        $this->assertGreaterThan(0, Processo::withoutGlobalScopes()->where('orgao_id', $o->id)->count());
    }

    public function test_turma_nao_recebe_alerta_por_email_e_ia_fica_desligada_por_padrao(): void
    {
        Notification::fake();
        config(['nexplangov.ia.habilitada' => true]);
        $o = $this->turma()['orgao'];

        $this->artisan('nexplangov:alertas')->assertSuccessful();
        foreach (User::where('orgao_id', $o->id)->get() as $u) {
            Notification::assertNothingSentTo($u);
        }

        $this->assertFalse(AssistenteIa::habilitadaPara($o));
        $this->assertTrue(AssistenteIa::habilitadaPara($this->orgao));
        config(['nexplangov.treinamento.ia' => true]);
        $this->assertTrue(AssistenteIa::habilitadaPara($o));
    }

    public function test_comando_prepara_lista_e_encerra(): void
    {
        $this->artisan('nexplangov:treinamento preparar --turma=2026a --dias=10')->expectsOutputToContain('Turma pronta')->assertSuccessful();
        $this->artisan('nexplangov:treinamento preparar --turma=2026a')->expectsOutputToContain('já existe')->assertFailed();
        $this->artisan('nexplangov:treinamento listar')->expectsOutputToContain('TREINO2026A')->assertSuccessful();
        $this->artisan('nexplangov:treinamento encerrar --turma=2026A')->expectsOutputToContain('encerrada')->assertSuccessful();
        $this->assertFalse(Orgao::where('sigla', 'TREINO2026A')->first()->ativo);
        $this->artisan('nexplangov:treinamento encerrar --turma=XYZ')->assertFailed();
    }

    public function test_admin_geral_prepara_e_encerra_turma_pelo_painel_master_e_senhas_aparecem_uma_vez(): void
    {
        $geral = $this->usuario('administrador-geral');

        $this->actingAs($geral)->post(route('treinamento.store'), ['turma' => 'abc', 'dias' => 15])->assertRedirect(route('master'));
        $o = Orgao::where('sigla', 'TREINOABC')->firstOrFail();

        $this->actingAs($geral)->get(route('master'))->assertOk()->assertSee('as senhas não aparecem de novo')->assertSee('@treinamento.nexplangov.local');
        $this->actingAs($geral)->get(route('master'))->assertOk()->assertDontSee('as senhas não aparecem de novo');

        $this->actingAs($this->u['planejamento'])->post(route('treinamento.store'), ['turma' => 'x', 'dias' => 5])->assertForbidden();
        $this->actingAs($geral)->post(route('treinamento.encerrar', $this->orgao))->assertNotFound();
        $this->actingAs($geral)->post(route('treinamento.encerrar', $o))->assertRedirect(route('master'));
        $this->assertFalse($o->fresh()->ativo);
    }
}
