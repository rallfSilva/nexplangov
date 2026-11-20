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
 * - Aplica o método escolhido (mediana, média, média ponderada pela quantidade ou menor preço) às cotações restantes.
 * - Devolve mínimo, média, mediana e média ponderada das cotações válidas e alerta quando a pesquisa está dispersa
 *   (coeficiente de variação acima de 25%), para o servidor conferir antes de fixar o preço estimado.
 */
final class PesquisaPrecos
{
    public const METODOS = ['mediana' => 'Mediana', 'media' => 'Média', 'ponderada' => 'Média ponderada', 'menor' => 'Menor preço'];

    /** Coeficiente de variação (%) a partir do qual a pesquisa é tratada como discrepante. */
    public const LIMITE_DISPERSAO = 25.0;

    public function __construct(private float $toleranciaDestoante = 0.5) {}

    /**
     * A quantidade da cotação (quando a fonte informa) é o peso da média ponderada; sem ela, o peso é 1.
     *
     * @param  list<array{valor: float|string, fonte: string, data: DateTimeInterface|string, quantidade?: float|string|null, desconsiderada?: bool, motivo?: ?string}>  $cotacoes
     * @return array{valor_unitario: float, metodo: string, validas: list<int>, descartadas: array<int, string>, alertas: list<string>, coeficiente_variacao: ?float, quantidade_validas: int, estatisticas: array{minimo: float, media: float, mediana: float, media_ponderada: float}, discrepante: bool}
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

        $pesos = [];
        foreach (array_keys($candidatas) as $i) {
            $q = $cotacoes[$i]['quantidade'] ?? null;
            $pesos[] = is_numeric($q) && (float) $q > 0 ? (float) $q : 1.0;
        }

        $estatisticas = [
            'minimo' => $valores === [] ? 0.0 : round(min($valores), 4),
            'media' => $valores === [] ? 0.0 : round(array_sum($valores) / count($valores), 4),
            'mediana' => round(self::mediana($valores), 4),
            'media_ponderada' => round(self::mediaPonderada($valores, $pesos), 4),
        ];

        $cv = self::coeficienteVariacao($valores);
        $discrepante = $cv !== null && $cv > self::LIMITE_DISPERSAO;
        if ($discrepante) {
            $alertas[] = sprintf('Preços discrepantes: coeficiente de variação de %s%%, acima de %d%%. Confira se os itens cotados são equivalentes e justifique o preço escolhido (art. 23).', number_format($cv, 2, ',', '.'), self::LIMITE_DISPERSAO);
        }

        $valor = match (true) {
            $valores === [] => 0.0,
            $metodo === 'media' => $estatisticas['media'],
            $metodo === 'ponderada' => $estatisticas['media_ponderada'],
            $metodo === 'menor' => $estatisticas['minimo'],
            default => $estatisticas['mediana'],
        };

        return [
            'valor_unitario' => round($valor, 4),
            'metodo' => array_key_exists($metodo, self::METODOS) ? $metodo : 'mediana',
            'validas' => array_keys($candidatas),
            'descartadas' => $descartadas,
            'alertas' => $alertas,
            'coeficiente_variacao' => $cv,
            'quantidade_validas' => count($valores),
            'estatisticas' => $estatisticas,
            'discrepante' => $discrepante,
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

    /**
     * Média dos valores pesada pelas quantidades das contratações de origem.
     *
     * @param  list<float>  $valores
     * @param  list<float>  $pesos
     */
    public static function mediaPonderada(array $valores, array $pesos): float
    {
        $soma = array_sum($pesos);
        if ($valores === [] || $soma <= 0) {
            return 0.0;
        }

        return array_sum(array_map(fn ($v, $p) => $v * $p, $valores, $pesos)) / $soma;
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
