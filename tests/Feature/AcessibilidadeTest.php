<?php

namespace Tests\Feature;

use App\Models\Contrato;
use App\Models\Orgao;
use App\Models\Processo;
use App\Models\User;
use App\Support\GeradorDeCarga;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verificação automática de acessibilidade (eMAG 3.1 / WCAG 2.1 nível AA) no HTML das telas principais:
 * idioma, título, cabeçalho, atalho para o conteúdo, rótulo em todo campo, texto alternativo em imagem
 * e nome acessível em botão e link. A checagem de contraste e foco fica no script com axe-core
 * (scripts/qualidade/acessibilidade.mjs).
 */
class AcessibilidadeTest extends TestCase
{
    use RefreshDatabase;

    private const TELAS = [
        'painel', 'processos.index', 'processos.create', 'pca.index', 'demandas.index', 'demandas.create',
        'contratos.index', 'contratos.create', 'atas.index', 'ordens.index', 'requisicoes.index', 'fornecedores.index',
        'fornecedores.create', 'unidades.index', 'usuarios.index', 'usuarios.create', 'auditoria.index', 'lgpd.index',
        'governanca', 'relatorios.gestao', 'capacitacao.index', 'catalogo.index', 'catalogo.orgao.index', 'catalogo.orgao.create', 'parametros.index', 'integracoes.index', 'dispensa.simulador', 'irp.index',
        'modelos.index', 'modelos.create', 'fluxos.index', 'fluxos.create', 'unidades.create', 'pncp.index', 'seguranca',
    ];

    public function test_telas_principais_passam_na_checagem_de_acessibilidade(): void
    {
        $this->semearPerfis();
        $orgao = Orgao::factory()->create();
        $admin = $this->todosOsPerfis($orgao);
        app(GeradorDeCarga::class)->gerar($orgao, 10, $admin);

        $problemas = [];
        foreach (self::TELAS as $nome) {
            $resposta = $this->actingAs($admin)->get(route($nome));
            if ($resposta->status() !== 200) {
                $problemas[] = "{$nome}: status {$resposta->status()}";

                continue;
            }
            $html = $resposta->getContent();
            foreach ($this->verificar($html) as $p) {
                $problemas[] = "{$nome}: {$p}";
            }
        }

        auth()->logout();
        foreach (['login', 'password.request', 'lgpd.publico', 'privacidade', 'assinaturas.validar'] as $nome) {
            foreach ($this->verificar($this->get(route($nome))->assertOk()->getContent()) as $p) {
                $problemas[] = "{$nome}: {$p}";
            }
        }

        $this->assertSame([], $problemas, implode("\n", $problemas));
    }

    public function test_tela_de_processo_com_abas_tambem_passa(): void
    {
        $this->semearPerfis();
        $orgao = Orgao::factory()->create();
        $admin = $this->todosOsPerfis($orgao);
        app(GeradorDeCarga::class)->gerar($orgao, 5, $admin);
        $this->actingAs($admin);

        $processo = Processo::first();
        $contrato = Contrato::first();
        $problemas = [];
        foreach ([route('processos.show', $processo), route('documentos.edit', [$processo, 'etp']), route('contratos.show', $contrato)] as $url) {
            foreach ($this->verificar($this->get($url)->assertOk()->getContent()) as $p) {
                $problemas[] = "{$url}: {$p}";
            }
        }

        $this->assertSame([], $problemas, implode("\n", $problemas));
    }

    /** Usuário do órgão com todos os perfis do órgão, para abrir todas as telas numa passada só. */
    private function todosOsPerfis(Orgao $orgao): User
    {
        $u = $this->usuario('administrador-orgao', $orgao);
        $u->assignRole(['autoridade', 'agente-contratacao', 'planejamento', 'juridico', 'gestor-contrato', 'requisitante', 'auditor']);

        return $u;
    }

    /** @return list<string> */
    private function verificar(string $html): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR);
        libxml_clear_errors();
        $x = new DOMXPath($dom);
        $p = [];

        if ($x->query('//html[@lang="pt-BR"]')->length !== 1) {
            $p[] = 'sem lang="pt-BR" no <html>';
        }
        if (trim((string) $x->query('//title')->item(0)?->textContent) === '') {
            $p[] = 'sem <title>';
        }
        if ($x->query('//h1')->length < 1) {
            $p[] = 'sem <h1>';
        }
        if ($x->query('//main')->length !== 1) {
            $p[] = 'precisa de exatamente um <main>';
        }

        $ids = [];
        foreach ($x->query('//*[@id]') as $el) {
            $id = $el->getAttribute('id');
            if (isset($ids[$id])) {
                $p[] = "id repetido: {$id}";
            }
            $ids[$id] = true;
        }

        foreach ($x->query('//input[not(@type="hidden") and not(@type="submit") and not(@type="button")] | //select | //textarea') as $campo) {
            /** @var DOMElement $campo */
            if (! $this->temRotulo($x, $campo)) {
                $p[] = 'campo sem rótulo: '.$campo->nodeName.'[name='.$campo->getAttribute('name').']';
            }
        }

        foreach ($x->query('//img') as $img) {
            if (! $img->hasAttribute('alt')) {
                $p[] = 'imagem sem alt: '.$img->getAttribute('src');
            }
        }

        foreach ($x->query('//button | //a[@href]') as $el) {
            /** @var DOMElement $el */
            $nome = trim($el->textContent).$el->getAttribute('aria-label').$el->getAttribute('title');
            foreach ($x->query('.//img[@alt]', $el) as $img) {
                $nome .= $img->getAttribute('alt');
            }
            if (trim($nome) === '') {
                $p[] = $el->nodeName.' sem nome acessível'.($el->getAttribute('href') ? ' ('.$el->getAttribute('href').')' : '');
            }
        }

        foreach ($x->query('//table') as $tabela) {
            if ($x->query('.//th', $tabela)->length === 0) {
                $p[] = 'tabela sem cabeçalho <th>';
            }
        }

        return array_values(array_unique($p));
    }

    private function temRotulo(DOMXPath $x, DOMElement $campo): bool
    {
        if ($campo->getAttribute('aria-label') !== '' || $campo->getAttribute('aria-labelledby') !== '' || $campo->getAttribute('title') !== '') {
            return true;
        }
        $id = $campo->getAttribute('id');
        if ($id !== '' && $x->query('//label[@for="'.$id.'"]')->length > 0) {
            return true;
        }

        return $x->query('ancestor::label', $campo)->length > 0;
    }
}
