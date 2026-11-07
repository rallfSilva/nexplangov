<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\AutosProcesso as A;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Autos do processo numerados em ordem cronológica e agrupados por fase (art. 12, I). */
class AutosProcessoTest extends TestCase
{
    public function test_numera_pela_data_de_entrada_e_agrupa_por_fase(): void
    {
        $d = fn (string $s) => CarbonImmutable::parse($s);
        $autos = A::montar([
            ['data' => $d('2026-03-10'), 'fase' => 'juridico', 'titulo' => 'Parecer'],
            ['data' => $d('2026-03-01'), 'fase' => 'instrucao', 'titulo' => 'DFD'],
            ['data' => $d('2026-03-05'), 'fase' => 'juntada', 'titulo' => 'Certidão'],
            ['data' => $d('2026-03-01'), 'fase' => 'instrucao', 'titulo' => 'ETP'],
        ]);

        $this->assertSame(['DFD', 'ETP', 'Certidão', 'Parecer'], array_column($autos['itens'], 'titulo'));
        $this->assertSame([1, 2, 3, 4], array_column($autos['itens'], 'numero'));
        $this->assertSame(['instrucao', 'juridico', 'juntada'], array_keys($autos['arvore']), 'fases na ordem do processo');
        $this->assertSame(3, $autos['arvore']['juntada'][0]['numero'], 'a numeração é única, não por fase');
    }

    public function test_desentranhamento_exige_motivo(): void
    {
        $this->assertCount(1, A::motivosDesentranhamento(false, 'engano'));
        $this->assertSame([], A::motivosDesentranhamento(false, 'Juntado no processo errado.'));
        $this->assertCount(1, A::motivosDesentranhamento(true, 'Juntado no processo errado.'));
    }

    public function test_conta_paginas_do_pdf(): void
    {
        $this->assertSame(2, A::paginasPdf("%PDF-1.4\n1 0 obj << /Type /Pages /Count 2 >>\n2 0 obj << /Type /Page >>\n3 0 obj << /Type/Page >>"));
        $this->assertNull(A::paginasPdf('não é pdf'));
    }
}
