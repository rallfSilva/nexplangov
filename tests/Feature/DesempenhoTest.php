<?php

namespace Tests\Feature;

use App\Models\Orgao;
use App\Support\GeradorDeCarga;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Orçamento de consultas por tela. Mede quantas consultas cada lista faz com pouco e com muito dado:
 * se o número cresce junto com os registros, há consulta repetida por linha (N+1) e a tela fica lenta
 * no órgão grande. O limite absoluto pega tela que já nasce pesada.
 */
class DesempenhoTest extends TestCase
{
    use RefreshDatabase;

    private const TELAS = [
        'painel', 'processos.index', 'pca.index', 'demandas.index', 'contratos.index', 'fornecedores.index',
        'atas.index', 'ordens.index', 'requisicoes.index', 'governanca', 'auditoria.index', 'pncp.index',
    ];

    private const LIMITE_ABSOLUTO = 60;

    private const CRESCIMENTO_TOLERADO = 3;

    public function test_listas_nao_fazem_uma_consulta_por_linha(): void
    {
        $this->semearPerfis();
        $orgao = Orgao::factory()->create();
        $u = $this->usuario('administrador-orgao', $orgao);
        $u->assignRole(['autoridade', 'agente-contratacao', 'planejamento', 'juridico', 'gestor-contrato', 'requisitante', 'auditor']);
        $gerador = app(GeradorDeCarga::class);

        $gerador->gerar($orgao, 5, $u);
        $poucos = $this->medir($u);

        $gerador->gerar($orgao, 30, $u);
        $muitos = $this->medir($u);

        $problemas = [];
        foreach (self::TELAS as $tela) {
            if ($muitos[$tela] > self::LIMITE_ABSOLUTO) {
                $problemas[] = "{$tela}: {$muitos[$tela]} consultas (limite ".self::LIMITE_ABSOLUTO.')';
            }
            if ($muitos[$tela] - $poucos[$tela] > self::CRESCIMENTO_TOLERADO) {
                $problemas[] = "{$tela}: {$poucos[$tela]} consultas com 5 processos e {$muitos[$tela]} com 35 (N+1)";
            }
        }

        $this->assertSame([], $problemas, implode("\n", $problemas));
    }

    /** @return array<string, int> */
    private function medir($u): array
    {
        $r = [];
        foreach (self::TELAS as $tela) {
            $this->actingAs($u);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route($tela))->assertOk();
            $r[$tela] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        return $r;
    }
}
