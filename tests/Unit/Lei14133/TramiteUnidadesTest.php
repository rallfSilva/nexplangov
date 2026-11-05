<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\TramiteUnidades as T;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Tramitação dos autos entre unidades: remessa, recebimento, cancelamento, arquivamento e permanência. */
class TramiteUnidadesTest extends TestCase
{
    public function test_remessa_exige_estar_na_unidade_destino_diferente_e_despacho(): void
    {
        $this->assertSame([], T::motivosRemessa(false, false, 1, 2, true, 'Encaminho para parecer jurídico.'));
        $this->assertCount(1, T::motivosRemessa(false, false, 1, 1, true, 'Encaminho para parecer jurídico.'));
        $this->assertCount(1, T::motivosRemessa(false, false, 1, 2, false, 'Encaminho para parecer jurídico.'));
        $this->assertCount(1, T::motivosRemessa(false, false, 1, 2, true, 'curto'));
        $this->assertCount(2, T::motivosRemessa(true, true, 1, 2, true, 'Encaminho para parecer jurídico.'));
    }

    public function test_recebimento_e_cancelamento(): void
    {
        $this->assertSame([], T::motivosRecebimento(true, true));
        $this->assertCount(1, T::motivosRecebimento(true, false));
        $this->assertCount(1, T::motivosRecebimento(false, true));

        $this->assertSame([], T::motivosCancelamento(true, true, 'Unidade de destino errada.'));
        $this->assertStringContainsString('já foi recebida', T::motivosCancelamento(false, true, 'Unidade de destino errada.')[0]);
        $this->assertCount(2, T::motivosCancelamento(true, false, ''));
    }

    public function test_arquivamento_so_de_processo_encerrado(): void
    {
        $this->assertSame([], T::motivosArquivamento(true, false, false, true, 'Contrato assinado e publicado.'));
        $this->assertStringContainsString('encerrado', T::motivosArquivamento(false, false, false, true, 'Contrato assinado e publicado.')[0]);
        $this->assertCount(1, T::motivosDesarquivamento(true, true, 'curto'));
        $this->assertSame([], T::motivosDesarquivamento(true, true, 'Pedido de vista do tribunal de contas.'));
    }

    public function test_periodos_contam_unidade_transito_e_ignoram_remessa_cancelada(): void
    {
        $d = fn (string $s) => CarbonImmutable::parse($s);
        $tramites = [
            ['tipo' => 'remessa', 'origem' => 1, 'destino' => 3, 'enviado_em' => $d('2026-01-02 08:00'), 'recebido_em' => null, 'cancelado_em' => $d('2026-01-02 09:00')],
            ['tipo' => 'remessa', 'origem' => 1, 'destino' => 2, 'enviado_em' => $d('2026-01-05 08:00'), 'recebido_em' => $d('2026-01-06 08:00'), 'cancelado_em' => null, 'recebido_por' => 7],
            ['tipo' => 'arquivamento', 'origem' => 2, 'destino' => null, 'enviado_em' => $d('2026-01-08 08:00'), 'recebido_em' => null, 'cancelado_em' => null],
        ];

        $p = T::periodos($d('2026-01-01 08:00'), 1, 5, $tramites, $d('2026-01-10 08:00'));

        $this->assertCount(3, $p);
        $this->assertSame([1, false, 4.0, 5], [$p[0]['unidade'], $p[0]['transito'], $p[0]['dias'], $p[0]['responsavel']]);
        $this->assertSame([null, true, 1.0], [$p[1]['unidade'], $p[1]['transito'], $p[1]['dias']]);
        $this->assertSame([2, 4.0, 7, null], [$p[2]['unidade'], $p[2]['dias'], $p[2]['responsavel'], $p[2]['fim']]);
        $this->assertSame([1 => 4.0, 2 => 4.0, 0 => 1.0], T::diasPorUnidade($p));
    }

    public function test_remessa_em_transito_termina_os_periodos(): void
    {
        $d = fn (string $s) => CarbonImmutable::parse($s);
        $p = T::periodos($d('2026-01-01'), 1, null, [['tipo' => 'remessa', 'origem' => 1, 'destino' => 2, 'enviado_em' => $d('2026-01-03'), 'recebido_em' => null, 'cancelado_em' => null]], $d('2026-01-04'));

        $this->assertCount(2, $p);
        $this->assertTrue($p[1]['transito']);
        $this->assertNull($p[1]['fim']);
        $this->assertSame(1.0, $p[1]['dias']);
    }
}
