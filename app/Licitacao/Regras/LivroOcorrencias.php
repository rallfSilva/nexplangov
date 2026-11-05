<?php

namespace App\Licitacao\Regras;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Livro de ocorrências do contrato (art. 117 da Lei 14.133).
 *
 * - § 1º: o fiscal anota em registro próprio as ocorrências da execução e determina o necessário para
 *   regularizar faltas ou defeitos.
 * - § 2º: o que exceder a sua competência, o fiscal informa aos superiores a tempo de adotar as medidas.
 *
 * O livro é inalterável: cada anotação leva o hash da anterior, e corrigir é registrar uma retificação.
 */
final class LivroOcorrencias
{
    public const TIPOS = [
        'ocorrencia' => 'Ocorrência na execução (art. 117, § 1º)',
        'determinacao' => 'Determinação para regularizar falta ou defeito (art. 117, § 1º)',
        'comunicacao_superior' => 'Comunicação aos superiores do que excede a competência do fiscal (art. 117, § 2º)',
        'retificacao' => 'Retificação de anotação anterior',
    ];

    public const DESCRICAO_MINIMA = 20;

    /**
     * @return list<string> motivos que impedem a anotação; vazio libera
     */
    public static function validar(string $tipo, string $descricao, CarbonInterface $ocorridaEm, CarbonInterface $hoje, CarbonInterface $inicioVigencia, bool $designado, bool $retificaAnterior, ?CarbonInterface $prazo = null): array
    {
        $m = [];
        $hoje = CarbonImmutable::parse($hoje)->startOfDay();
        $ocorridaEm = CarbonImmutable::parse($ocorridaEm)->startOfDay();

        if (! $designado) {
            $m[] = 'Só o fiscal e o gestor designados para o contrato anotam no livro de ocorrências (art. 117, caput e § 1º).';
        }
        if (! array_key_exists($tipo, self::TIPOS)) {
            $m[] = 'Escolha o tipo da anotação.';
        }
        if (mb_strlen(trim($descricao)) < self::DESCRICAO_MINIMA) {
            $m[] = 'Descreva a ocorrência com pelo menos '.self::DESCRICAO_MINIMA.' caracteres.';
        }
        if ($ocorridaEm->greaterThan($hoje)) {
            $m[] = 'A data da ocorrência não pode ser futura.';
        }
        if ($ocorridaEm->lessThan(CarbonImmutable::parse($inicioVigencia)->startOfDay())) {
            $m[] = 'A data da ocorrência é anterior ao início da vigência do contrato.';
        }
        if ($tipo === 'retificacao' && ! $retificaAnterior) {
            $m[] = 'Indique qual anotação está sendo retificada.';
        }
        if ($tipo === 'determinacao' && $prazo !== null && CarbonImmutable::parse($prazo)->startOfDay()->lessThan($hoje)) {
            $m[] = 'O prazo para regularizar não pode estar no passado.';
        }

        return $m;
    }

    /** Hash da anotação, encadeado ao hash da anterior do mesmo contrato. */
    public static function hash(array $campos, ?string $anterior): string
    {
        ksort($campos);

        return hash('sha256', ($anterior ?? '').'|'.json_encode($campos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
