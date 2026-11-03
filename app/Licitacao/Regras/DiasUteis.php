<?php

namespace App\Licitacao\Regras;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Contagem de dias úteis para os prazos da lei (art. 164 e outros): sábados, domingos e feriados
 * nacionais não contam. Feriados estaduais e municipais entram pelo construtor (config nexplangov.feriados_extras).
 */
final class DiasUteis
{
    /** @param list<string> $feriadosExtras datas Y-m-d */
    public function __construct(private array $feriadosExtras = []) {}

    public function ehUtil(CarbonInterface $data): bool
    {
        return ! $data->isWeekend() && ! in_array($data->toDateString(), $this->feriados($data->year), true);
    }

    public function somar(CarbonInterface $data, int $dias): CarbonImmutable
    {
        return $this->andar($data, $dias, 1);
    }

    public function subtrair(CarbonInterface $data, int $dias): CarbonImmutable
    {
        return $this->andar($data, $dias, -1);
    }

    /** Último dia útil anterior à data (a própria data não conta). */
    public function ultimoUtilAntes(CarbonInterface $data): CarbonImmutable
    {
        return $this->andar($data, 1, -1);
    }

    /** @return list<string> */
    public function feriados(int $ano): array
    {
        $pascoa = $this->pascoa($ano);

        return array_merge([
            "$ano-01-01", "$ano-04-21", "$ano-05-01", "$ano-09-07", "$ano-10-12", "$ano-11-02", "$ano-11-15", "$ano-12-25",
            $ano >= 2024 ? "$ano-11-20" : "$ano-01-01", // Consciência Negra, feriado nacional desde a Lei 14.759/2023
            $pascoa->subDays(48)->toDateString(), $pascoa->subDays(47)->toDateString(), // Carnaval (ponto facultativo, tratado como dia sem expediente)
            $pascoa->subDays(2)->toDateString(), // Sexta-feira Santa
        ], $this->feriadosExtras);
    }

    private function andar(CarbonInterface $data, int $dias, int $sentido): CarbonImmutable
    {
        $d = CarbonImmutable::parse($data->toDateString());

        while ($dias > 0) {
            $d = $d->addDays($sentido);

            if ($this->ehUtil($d)) {
                $dias--;
            }
        }

        return $d;
    }

    /** Domingo de Páscoa (algoritmo de Meeus, Jones e Butcher). */
    private function pascoa(int $ano): CarbonImmutable
    {
        $a = $ano % 19;
        $b = intdiv($ano, 100);
        $c = $ano % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($ano, $mes, $dia);
    }
}
