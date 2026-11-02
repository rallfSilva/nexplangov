<?php

namespace App\Licitacao\Regras;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Duração dos contratos (arts. 105 a 114 da Lei 14.133).
 * - Serviços e fornecimentos contínuos: até 5 anos na contratação (art. 106), prorrogáveis
 *   até o total de 10 anos (art. 107).
 * - Prazos maiores só com fundamento especial (arts. 108 a 110), que precisa ficar registrado.
 */
final class VigenciaContratual
{
    /** @return list<string> mensagens de bloqueio (vazia = permitido) */
    public function validarInicial(DateTimeInterface|string $inicio, DateTimeInterface|string $fim, bool $continuo, ?string $fundamentoEspecial = null): array
    {
        $inicio = CarbonImmutable::parse($inicio);
        $fim = CarbonImmutable::parse($fim);
        $erros = [];

        if ($fim->lessThanOrEqualTo($inicio)) {
            $erros[] = 'O fim da vigência precisa ser depois do início.';
        }

        if ($continuo && ! $fundamentoEspecial && $fim->greaterThan($inicio->addYears(5)->subDay())) {
            $erros[] = 'Serviço ou fornecimento contínuo pode ser contratado por até 5 anos (art. 106). Para prazo maior, informe o fundamento (arts. 108 a 110).';
        }

        return $erros;
    }

    /** @return list<string> */
    public function validarProrrogacao(DateTimeInterface|string $inicioOriginal, DateTimeInterface|string $novoFim, bool $continuo, ?string $fundamentoEspecial = null): array
    {
        $inicio = CarbonImmutable::parse($inicioOriginal);
        $novoFim = CarbonImmutable::parse($novoFim);

        if ($continuo && ! $fundamentoEspecial && $novoFim->greaterThan($inicio->addYears(10)->subDay())) {
            return ['Com a prorrogação, a vigência passaria de 10 anos, o máximo do art. 107 para serviços e fornecimentos contínuos.'];
        }

        return [];
    }

    /** Dias até o fim da vigência (negativo se já venceu). */
    public static function diasParaVencer(DateTimeInterface|string $fim, DateTimeInterface|string|null $hoje = null): int
    {
        return (int) CarbonImmutable::parse($hoje ?? 'today')->startOfDay()->diffInDays(CarbonImmutable::parse($fim)->startOfDay(), false);
    }
}
