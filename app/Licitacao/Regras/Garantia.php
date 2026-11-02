<?php

namespace App\Licitacao\Regras;

/**
 * Garantia contratual (arts. 96 a 99 da Lei 14.133): até 5% do valor inicial; até 10% quando a
 * complexidade técnica e os riscos justificarem; até 30% em obras e serviços de engenharia de grande
 * vulto, com seguro-garantia e cláusula de retomada.
 */
final class Garantia
{
    /** @return list<string> */
    public function validar(?float $percentual, ?string $justificativa, bool $grandeVultoEngenharia = false): array
    {
        if ($percentual === null || $percentual == 0.0) {
            return [];
        }

        if ($percentual < 0) {
            return ['O percentual da garantia não pode ser negativo.'];
        }

        if ($grandeVultoEngenharia) {
            return $percentual > 30 ? ['Em obra ou serviço de engenharia de grande vulto, a garantia vai até 30% (art. 99).'] : [];
        }

        if ($percentual > 10) {
            return ['A garantia vai até 10% do valor inicial (art. 98, parágrafo único).'];
        }

        if ($percentual > 5 && trim((string) $justificativa) === '') {
            return ['Garantia acima de 5% exige justificativa pela complexidade técnica e pelos riscos (art. 98, parágrafo único).'];
        }

        return [];
    }
}
