<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Enums\CriterioJulgamento as C;
use App\Licitacao\Regras\DiasUteis;
use App\Licitacao\Regras\Julgamento;
use App\Licitacao\Regras\PrazosEdital;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class JulgamentoTest extends TestCase
{
    private function p(int $id, float $valor, bool $me = false, string $s = 'classificada'): array
    {
        return ['id' => $id, 'valor' => $valor, 'me_epp' => $me, 'situacao' => $s];
    }

    public function test_menor_preco_ordena_do_menor_para_o_maior_e_ignora_desclassificadas(): void
    {
        $r = (new Julgamento)->classificar([$this->p(1, 10), $this->p(2, 8), $this->p(3, 5, false, 'desclassificada'), $this->p(4, 9)], C::MenorPreco);
        $this->assertSame([2, 4, 1], $r);
    }

    public function test_maior_desconto_e_maior_lance_ordenam_do_maior_para_o_menor(): void
    {
        $j = new Julgamento;
        $this->assertSame([2, 1], $j->classificar([$this->p(1, 10), $this->p(2, 15)], C::MaiorDesconto));
        $this->assertSame([2, 1], $j->classificar([$this->p(1, 100), $this->p(2, 120)], C::MaiorLance));
    }

    public function test_empatadas_sao_as_de_mesmo_valor_da_primeira(): void
    {
        $this->assertSame([1, 3], (new Julgamento)->empatadas([$this->p(1, 8), $this->p(2, 9), $this->p(3, 8)], C::MenorPreco));
    }

    public function test_empate_ficto_da_me_epp_ate_10_por_cento_e_5_no_pregao(): void
    {
        $j = new Julgamento;
        $props = [$this->p(1, 100), $this->p(2, 104, true), $this->p(3, 109, true), $this->p(4, 111, true)];

        $this->assertSame([2, 3], $j->empateFicto($props, C::MenorPreco, false));
        $this->assertSame([2], $j->empateFicto($props, C::MenorPreco, true));
    }

    public function test_empate_ficto_nao_vale_se_a_melhor_ja_e_me_epp_ou_fora_do_menor_preco(): void
    {
        $j = new Julgamento;
        $this->assertSame([], $j->empateFicto([$this->p(1, 100, true), $this->p(2, 101, true)], C::MenorPreco, true));
        $this->assertSame([], $j->empateFicto([$this->p(1, 100), $this->p(2, 101, true)], C::MaiorDesconto, true));
    }

    public function test_lance_precisa_melhorar_o_melhor_vigente_pelo_intervalo(): void
    {
        $j = new Julgamento;
        $this->assertTrue($j->lanceValido(90, null, 1, C::MenorPreco));
        $this->assertTrue($j->lanceValido(98.99, 100, 1, C::MenorPreco));
        $this->assertFalse($j->lanceValido(99.50, 100, 1, C::MenorPreco));
        $this->assertFalse($j->lanceValido(100, 100, 0, C::MenorPreco));
        $this->assertFalse($j->lanceValido(0, null, 0, C::MenorPreco));
        $this->assertTrue($j->lanceValido(110, 100, 5, C::MaiorLance));
        $this->assertFalse($j->lanceValido(102, 100, 5, C::MaiorLance));
    }

    public function test_preco_acima_do_orcamento_e_piso_de_engenharia(): void
    {
        $j = new Julgamento;
        $this->assertTrue($j->acimaDoOrcamento(100.01, 100));
        $this->assertFalse($j->acimaDoOrcamento(100, 100));
        $this->assertTrue($j->inexequivelEngenharia(74.99, 100));
        $this->assertFalse($j->inexequivelEngenharia(75, 100));
    }

    public function test_dias_uteis_pulam_fim_de_semana_e_feriado(): void
    {
        $d = new DiasUteis;
        // 2026-11-02 (segunda) é feriado de Finados; 2026-11-06 é sexta.
        $this->assertFalse($d->ehUtil(CarbonImmutable::parse('2026-11-02')));
        $this->assertSame('2026-11-05', $d->somar(CarbonImmutable::parse('2026-10-30'), 3)->toDateString()); // sex -> seg(feriado) pula -> ter 3, qua 4, qui 5
        $this->assertContains('2026-04-03', $d->feriados(2026)); // Sexta-feira Santa
        $this->assertContains('2026-02-17', $d->feriados(2026)); // Terça de Carnaval
    }

    public function test_prazos_do_art_164(): void
    {
        $p = new PrazosEdital(new DiasUteis);
        $abertura = CarbonImmutable::parse('2026-12-10 09:00'); // quinta

        $this->assertSame('2026-12-07', $p->limiteProtocolo($abertura)->toDateString());
        $this->assertTrue($p->tempestiva(CarbonImmutable::parse('2026-12-07 23:00'), $abertura));
        $this->assertFalse($p->tempestiva(CarbonImmutable::parse('2026-12-08'), $abertura));

        // Recebida cedo: 3 dias úteis depois. Recebida em cima da hora: limitada ao último dia útil antes da abertura.
        $this->assertSame('2026-11-26', $p->prazoResposta(CarbonImmutable::parse('2026-11-23'), $abertura)->toDateString());
        $this->assertSame('2026-12-09', $p->prazoResposta(CarbonImmutable::parse('2026-12-07'), $abertura)->toDateString());
    }
}
