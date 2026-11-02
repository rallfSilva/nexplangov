<?php

namespace App\Licitacao\Regras;

use App\Licitacao\Enums\FontePreco;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Calcula o preço estimado de um item a partir das cotações (art. 23 da Lei 14.133).
 *
 * - Descarta cotações fora da validade legal: contratações similares com mais de 1 ano e
 *   orçamentos de fornecedor com mais de 6 meses (art. 23, § 1º, II e IV).
 * - Marca como destoantes as cotações muito acima ou muito abaixo da mediana. A lei não fixa o
 *   percentual; o padrão é 50% e o órgão pode mudar. A desconsideração sempre fica registrada.
 * - Aplica o método escolhido (mediana, média ou menor preço) às cotações restantes.
 */
final class PesquisaPrecos
{
    public const METODOS = ['mediana' => 'Mediana', 'media' => 'Média', 'menor' => 'Menor preço'];

    public function __construct(private float $toleranciaDestoante = 0.5) {}

    /**
     * @param  list<array{valor: float|string, fonte: string, data: DateTimeInterface|string, desconsiderada?: bool, motivo?: ?string}>  $cotacoes
     * @return array{valor_unitario: float, metodo: string, validas: list<int>, descartadas: array<int, string>, alertas: list<string>, coeficiente_variacao: ?float, quantidade_validas: int}
     */
    public function calcular(array $cotacoes, string $metodo = 'mediana', DateTimeInterface|string|null $referencia = null): array
    {
        $referencia = CarbonImmutable::parse($referencia ?? 'today')->startOfDay();
        $descartadas = [];
        $candidatas = [];
        $alertas = [];

        foreach ($cotacoes as $i => $c) {
            $valor = (float) $c['valor'];
            $fonte = FontePreco::from($c['fonte']);
            $data = CarbonImmutable::parse($c['data'])->startOfDay();

            if (! empty($c['desconsiderada'])) {
                $descartadas[$i] = $c['motivo'] ?? 'Desconsiderada pelo responsável pela pesquisa.';

                continue;
            }

            if ($valor <= 0) {
                $descartadas[$i] = 'Valor zerado ou negativo.';

                continue;
            }

            if ($data->greaterThan($referencia)) {
                $descartadas[$i] = 'Data da cotação posterior à data da pesquisa.';

                continue;
            }

            if ($meses = $fonte->validadeMeses()) {
                if ($data->lessThan($referencia->subMonthsNoOverflow($meses))) {
                    $descartadas[$i] = "Fora da validade: {$fonte->rotulo()} aceita no máximo {$meses} meses.";

                    continue;
                }
            }

            $candidatas[$i] = $valor;
        }

        // Destoantes em relação à mediana das candidatas (só faz sentido com 3 ou mais).
        if (count($candidatas) >= 3) {
            $mediana = self::mediana(array_values($candidatas));

            foreach ($candidatas as $i => $valor) {
                if ($valor > $mediana * (1 + $this->toleranciaDestoante)) {
                    $descartadas[$i] = sprintf('Excessivamente elevada: mais de %d%% acima da mediana.', $this->toleranciaDestoante * 100);
                    unset($candidatas[$i]);
                } elseif ($valor < $mediana * (1 - $this->toleranciaDestoante)) {
                    $descartadas[$i] = sprintf('Possivelmente inexequível: mais de %d%% abaixo da mediana.', $this->toleranciaDestoante * 100);
                    unset($candidatas[$i]);
                }
            }
        }

        $valores = array_values($candidatas);

        if (count($valores) < 3) {
            $alertas[] = 'Menos de 3 preços válidos. Junte mais cotações ou justifique no processo (art. 23, § 1º).';
        }

        $fornecedores = array_filter($cotacoes, fn ($c, $i) => isset($candidatas[$i]) && $c['fonte'] === FontePreco::Fornecedor->value, ARRAY_FILTER_USE_BOTH);
        if ($fornecedores !== [] && count($fornecedores) < 3 && count($valores) === count($fornecedores)) {
            $alertas[] = 'Pesquisa direta com fornecedores exige no mínimo 3 orçamentos (art. 23, § 1º, IV).';
        }

        $valor = match (true) {
            $valores === [] => 0.0,
            $metodo === 'media' => array_sum($valores) / count($valores),
            $metodo === 'menor' => min($valores),
            default => self::mediana($valores),
        };

        return [
            'valor_unitario' => round($valor, 4),
            'metodo' => array_key_exists($metodo, self::METODOS) ? $metodo : 'mediana',
            'validas' => array_keys($candidatas),
            'descartadas' => $descartadas,
            'alertas' => $alertas,
            'coeficiente_variacao' => self::coeficienteVariacao($valores),
            'quantidade_validas' => count($valores),
        ];
    }

    /** @param  list<float>  $valores */
    public static function mediana(array $valores): float
    {
        sort($valores);
        $n = count($valores);

        if ($n === 0) {
            return 0.0;
        }

        $meio = intdiv($n, 2);

        return $n % 2 ? $valores[$meio] : ($valores[$meio - 1] + $valores[$meio]) / 2;
    }

    /** Desvio padrão amostral dividido pela média, em %. Acima de 25% indica pesquisa dispersa. */
    public static function coeficienteVariacao(array $valores): ?float
    {
        $n = count($valores);

        if ($n < 2) {
            return null;
        }

        $media = array_sum($valores) / $n;
        $variancia = array_sum(array_map(fn ($v) => ($v - $media) ** 2, $valores)) / ($n - 1);

        return $media > 0 ? round(sqrt($variancia) / $media * 100, 2) : null;
    }
}
