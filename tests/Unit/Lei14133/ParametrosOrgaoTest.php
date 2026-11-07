<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\ConcessaoPermissao as C;
use App\Licitacao\Regras\ParametrosOrgao as P;
use PHPUnit\Framework\TestCase;

/** Parâmetros do órgão dentro de limites (11.2 e 11.5) e permissões extras sem acumular funções (art. 7º, § 1º). */
class ParametrosOrgaoTest extends TestCase
{
    public function test_valida_inteiros_e_listas_dentro_dos_limites(): void
    {
        $this->assertSame([15, []], P::validar('pagamento_prazo_dias', ' 15 '));
        $this->assertStringContainsString('de 1 a 30 dias', P::validar('pagamento_prazo_dias', '45')[1][0]);
        $this->assertSame('Prazo para pagar depois da liquidação: informe um número inteiro.', P::validar('pagamento_prazo_dias', '10.5')[1][0]);
        $this->assertSame([[120, 60, 30], []], P::validar('alertas_vigencia', '30, 120,60, 30'));
        $this->assertNotEmpty(P::validar('alertas_vigencia', '30, 400')[1]);
        $this->assertNotEmpty(P::validar('alertas_vigencia', '1,2,3,4,5,6,7,8,9')[1]);
        $this->assertNotEmpty(P::validar('alertas_vigencia', 'trinta')[1]);
        $this->assertSame([0, []], P::validar('ia_limite_diario', '0'), 'zero desliga a IA');
        $this->assertSame([null, ['Parâmetro desconhecido.']], P::validar('outro', '1'));
    }

    public function test_le_o_valor_guardado_no_tipo_certo(): void
    {
        $this->assertSame(10, P::ler('pagamento_prazo_dias', '10', 30));
        $this->assertSame(30, P::ler('pagamento_prazo_dias', null, 30));
        $this->assertSame([90, 30], P::ler('alertas_vigencia', '[90,30]', [60]));
    }

    public function test_concessao_respeita_segregacao_de_funcoes(): void
    {
        $just = 'Cobertura das férias do titular em dezembro.';
        $this->assertSame([], C::motivos('requisitante', 'contratos.ver', ['processos.ver'], $just));
        $this->assertStringContainsString('só vem do perfil', C::motivos('agente-contratacao', 'processos.parecer', [], $just)[0]);
        $this->assertSame(['O servidor já tem esta permissão.'], C::motivos('juridico', 'processos.ver', ['processos.ver'], $just));
        $this->assertStringContainsString('auditor', C::motivos('auditor', 'processos.gerenciar', [], $just)[0]);
        $this->assertSame([], C::motivos('auditor', 'contratos.ver', [], $just));
        $this->assertStringContainsString('não emite o parecer', C::motivos('juridico', 'processos.gerenciar', ['processos.parecer'], $just)[0]);
        $this->assertStringContainsString('não a homologa', C::motivos('autoridade', 'licitacao.conduzir', ['processos.autorizar'], $just)[0]);
        $this->assertStringContainsString('Justifique', C::motivos('requisitante', 'contratos.ver', [], 'curta')[0]);
    }
}
