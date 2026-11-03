<?php

namespace Tests\Feature;

use App\Models\Orgao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Rotas;
use Tests\TestCase;

/**
 * Varredura de segurança das rotas e dos cabeçalhos. Pega rota nova esquecida sem permissão,
 * tela que abre sem login e cabeçalho de proteção que sumiu.
 */
class SegurancaTest extends TestCase
{
    use RefreshDatabase;

    /** Rotas autenticadas que não precisam de permissão específica (são da própria conta do usuário). */
    private const SEM_PERMISSAO = ['logout', 'seguranca'];

    private function autenticadas(): array
    {
        return collect(Rotas::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth')))
            ->values()->all();
    }

    public function test_toda_rota_autenticada_exige_permissao(): void
    {
        $semPermissao = collect($this->autenticadas())
            ->reject(fn (Route $r) => in_array($r->getName(), self::SEM_PERMISSAO, true) || str_starts_with($r->uri(), 'user/'))
            ->reject(fn (Route $r) => collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'can:')))
            ->map(fn (Route $r) => implode('|', $r->methods()).' '.$r->uri())
            ->values()->all();

        $this->assertSame([], $semPermissao, 'Rotas sem can:<permissao>: '.implode(', ', $semPermissao));
        $this->assertGreaterThan(100, count($this->autenticadas()));
    }

    public function test_rotas_de_tela_exigem_conta_ativa(): void
    {
        $semAtivo = collect($this->autenticadas())
            ->reject(fn (Route $r) => str_starts_with($r->uri(), 'user/') || $r->getName() === 'logout')
            ->reject(fn (Route $r) => in_array('ativo', $r->gatherMiddleware(), true))
            ->map(fn (Route $r) => $r->uri())->values()->all();

        $this->assertSame([], $semAtivo);
    }

    public function test_visitante_sem_login_vai_para_a_tela_de_entrada(): void
    {
        $rotas = collect($this->autenticadas())
            ->filter(fn (Route $r) => in_array('GET', $r->methods(), true) && ! str_contains($r->uri(), '{') && ! str_starts_with($r->uri(), 'user/'));

        $this->assertNotEmpty($rotas);
        foreach ($rotas as $r) {
            $this->get('/'.$r->uri())->assertRedirect(route('login'));
        }
    }

    public function test_formularios_sem_token_csrf_sao_recusados(): void
    {
        $this->semearPerfis();
        $u = $this->usuario('planejamento', Orgao::factory()->create());

        // O teste desliga o CSRF por padrão em ambiente "testing"; aqui forçamos a verificação real.
        $this->app->instance('env', 'local');
        $this->actingAs($u)->post(route('demandas.store'), ['objeto' => 'x'])->assertStatus(419);
    }

    public function test_cabecalhos_de_protecao_em_toda_resposta(): void
    {
        $r = $this->get(route('login'));

        $r->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Cache-Control');

        $csp = $r->headers->get('Content-Security-Policy');
        foreach (["default-src 'self'", "frame-ancestors 'none'", "object-src 'none'", "form-action 'self'", "base-uri 'self'"] as $diretiva) {
            $this->assertStringContainsString($diretiva, $csp);
        }
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
    }

    public function test_arquivos_privados_nao_tem_rota_publica(): void
    {
        $this->assertFalse(Rotas::has('storage.local'));
        $this->get('/storage/orgaos/1/processos/1/etp/qualquer.pdf')->assertNotFound();
    }

    public function test_login_tem_limite_de_tentativas(): void
    {
        $this->semearPerfis();
        $u = $this->usuario('planejamento', Orgao::factory()->create());

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $u->email, 'password' => 'errada-'.$i]);
        }
        $this->post('/login', ['email' => $u->email, 'password' => 'errada'])->assertStatus(429);
    }

    public function test_cookie_de_sessao_e_http_only(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertContains(config('session.same_site'), ['lax', 'strict']);
    }
}
