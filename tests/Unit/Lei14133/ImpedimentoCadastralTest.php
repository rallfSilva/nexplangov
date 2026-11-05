<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\ImpedimentoCadastral as R;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Sanções do CEIS e do CNEP que impedem contratar (arts. 156 e 161). */
class ImpedimentoCadastralTest extends TestCase
{
    private function r(string $tipo, ?string $inicio, ?string $fim, string $cadastro = 'ceis'): array
    {
        return ['cadastro' => $cadastro, 'tipo' => $tipo, 'orgao' => 'Prefeitura de Exemplo (fictícia)', 'inicio' => $inicio, 'fim' => $fim];
    }

    public function test_so_conta_sancao_vigente_na_data(): void
    {
        $hoje = CarbonImmutable::parse('2026-06-10');
        $registros = [$this->r('Impedimento', '2025-01-01', '2026-06-10'), $this->r('Impedimento', '2025-01-01', '2026-06-09'), $this->r('Suspensão', '2026-07-01', null), $this->r('Inidoneidade', '2024-01-01', null)];

        $this->assertCount(2, R::vigentes($registros, $hoje));
    }

    public function test_inidoneidade_bloqueia_sempre_e_nao_aceita_ressalva(): void
    {
        $vig = [$this->r('Declaração de Inidoneidade', '2024-01-01', null)];

        $this->assertFalse(R::podeRessalvar($vig));
        $this->assertStringContainsString('art. 156, IV', R::motivos($vig, true)[0]);
    }

    public function test_impedimento_bloqueia_ate_a_analise_de_abrangencia(): void
    {
        $vig = [$this->r('Impedimento/proibição de contratar com prazo determinado', '2025-01-01', '2027-01-01')];

        $this->assertTrue(R::podeRessalvar($vig));
        $this->assertStringContainsString('§ 4º', R::motivos($vig, false)[0]);
        $this->assertSame([], R::motivos($vig, true));
    }

    public function test_multa_do_cnep_aparece_mas_nao_impede(): void
    {
        $vig = [$this->r('Multa', '2025-01-01', null, 'cnep')];

        $this->assertSame([], R::motivos($vig, false));
        $this->assertFalse(R::podeRessalvar($vig));
    }
}
