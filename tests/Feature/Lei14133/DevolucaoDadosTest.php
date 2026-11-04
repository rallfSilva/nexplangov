<?php

namespace Tests\Feature\Lei14133;

use App\Models\Contrato;
use App\Models\Fornecedor;
use App\Models\Orgao;
use App\Models\PacoteDevolucao;
use App\Models\PlanoContratacao;
use App\Models\Processo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** Pacote de devolução dos dados do órgão ao fim do contrato. */
class DevolucaoDadosTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->montarCenario();
    }

    public function test_administrador_do_orgao_gera_e_baixa_pacote_completo_e_so_do_seu_orgao(): void
    {
        $adm = $this->usuario('administrador-orgao', $this->orgao);
        $this->processo(['objeto' => 'Aquisição de papel (fictício)']);
        PlanoContratacao::create(['orgao_id' => $this->orgao->id, 'ano' => 2027, 'situacao' => 'rascunho']);
        $pf = Fornecedor::create(['orgao_id' => $this->orgao->id, 'tipo_pessoa' => 'PF', 'documento' => '529.982.247-25', 'razao_social' => 'Pessoa Fictícia']);
        $c = Contrato::create(['orgao_id' => $this->orgao->id, 'fornecedor_id' => $pf->id, 'numero' => '3', 'ano' => 2026, 'tipo' => 1, 'objeto' => '=HYPERLINK("x") Manutenção', 'categoria' => 8,
            'valor_inicial' => 1000, 'valor_global' => 1000, 'assinatura' => today(), 'vigencia_inicio' => today(), 'vigencia_fim' => today()->addYear(), 'situacao' => 'vigente']);
        $caminho = "orgaos/{$this->orgao->id}/contratos/{$c->id}/assinado.pdf";
        Storage::disk('local')->put($caminho, '%PDF-1.4 contrato fictício');
        $c->forceFill(['arquivo_caminho' => $caminho, 'arquivo_nome' => 'assinado.pdf'])->save();

        $outro = Orgao::factory()->create(['sigla' => 'OUT', 'cnpj' => '99.555.444/0001-00']);
        Processo::create(['orgao_id' => $outro->id, 'numero' => '1', 'ano' => 2026, 'objeto' => 'Segredo de outro órgão', 'modalidade' => 6, 'amparo_legal' => 1, 'criterio_julgamento' => 1, 'modo_disputa' => 1, 'categoria' => 2]);
        Storage::disk('local')->put("orgaos/{$outro->id}/x.pdf", 'outro');

        $this->actingAs($this->u['planejamento'])->get(route('devolucao.index'))->assertForbidden();
        $this->actingAs($adm)->get(route('devolucao.index'))->assertOk()->assertSee('Devolução de dados do órgão');
        $this->actingAs($adm)->post(route('devolucao.store'))->assertSessionHas('sucesso');

        $pacote = PacoteDevolucao::sole();
        $this->assertSame('pronto', $pacote->situacao, (string) $pacote->erro);
        $this->assertTrue($pacote->expira_em->between(now()->addDays(6), now()->addDays(8)));

        $resposta = $this->actingAs($adm)->get(route('devolucao.baixar', $pacote))->assertOk();
        $zipPath = tempnam(sys_get_temp_dir(), 'dev');
        file_put_contents($zipPath, $resposta->streamedContent());
        $z = new ZipArchive;
        $this->assertTrue($z->open($zipPath));

        $manifesto = json_decode($z->getFromName('manifesto.json'), true);
        $this->assertSame(hash('sha256', file_get_contents($zipPath)), $pacote->fresh()->sha256);
        foreach (['processos', 'planos_contratacao', 'contratos', 'fornecedores', 'users', 'unidades', 'registros_auditoria', 'perfis_usuarios', 'orgaos'] as $t) {
            $this->assertArrayHasKey($t, $manifesto['tabelas'], "tabela {$t} no pacote");
        }
        $this->assertSame(1, $manifesto['tabelas']['processos']['registros']);
        $this->assertSame(hash('sha256', $z->getFromName('dados/processos.csv')), $manifesto['arquivos']['dados/processos.csv']['sha256']);

        // Arquivo do contrato vem junto; o do outro órgão, não.
        $this->assertSame('%PDF-1.4 contrato fictício', $z->getFromName("arquivos/{$caminho}"));
        $this->assertFalse($z->getFromName("arquivos/orgaos/{$outro->id}/x.pdf"));

        $tudo = '';
        for ($i = 0; $i < $z->numFiles; $i++) {
            $tudo .= $z->getFromIndex($i);
        }
        $this->assertStringNotContainsString('Segredo de outro órgão', $tudo);
        $this->assertStringContainsString('52998224725', $z->getFromName('dados/fornecedores.jsonl'), 'CPF do fornecedor sai legível para o órgão');
        $this->assertStringNotContainsString('documento_hash', $z->getFromName('dados/fornecedores.csv'));
        $usuarios = $z->getFromName('dados/users.csv');
        $this->assertStringNotContainsString('password', $usuarios);
        $this->assertStringNotContainsString('two_factor', $usuarios);
        $this->assertStringContainsString("'=HYPERLINK", $z->getFromName('dados/contratos.csv'));
        $this->assertStringContainsString('ATENÇÃO (LGPD)', $z->getFromName('LEIA-ME.txt'));
        $z->close();
        @unlink($zipPath);

        $this->assertNotNull($pacote->fresh()->baixado_em);
        $this->assertDatabaseHas('registros_auditoria', ['evento' => 'exportacao', 'descricao' => 'Pacote de devolução de dados baixado']);

        // Administrador de outro órgão não enxerga o pacote.
        $this->actingAs($this->usuario('administrador-orgao', $outro))->get(route('devolucao.baixar', $pacote))->assertNotFound();
    }

    public function test_administrador_geral_escolhe_o_orgao_e_pacote_vencido_e_limpo(): void
    {
        $geral = $this->usuario('administrador-geral', null);
        $this->actingAs($geral)->post(route('devolucao.store'))->assertSessionHasErrors('orgao_id');
        $this->actingAs($geral)->post(route('devolucao.store'), ['orgao_id' => $this->orgao->id])->assertSessionHas('sucesso');

        $pacote = PacoteDevolucao::sole();
        $this->assertSame([$this->orgao->id, 'pronto'], [$pacote->orgao_id, $pacote->situacao]);
        $this->assertTrue(Storage::disk('local')->exists($pacote->arquivo_caminho));

        $this->travel(PacoteDevolucao::DIAS_DISPONIVEL + 1)->days();
        $this->actingAs($geral)->get(route('devolucao.baixar', $pacote))->assertSessionHas('erro');
        $this->artisan('nexplangov:devolucao-limpar')->expectsOutputToContain('1 pacote(s)')->assertSuccessful();
        $this->assertSame('expirado', $pacote->fresh()->situacao);
        $this->assertFalse(Storage::disk('local')->exists($pacote->arquivo_caminho));
    }
}
