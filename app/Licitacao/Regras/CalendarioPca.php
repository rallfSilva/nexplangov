<?php

namespace App\Licitacao\Regras;

/**
 * Calendário de elaboração do PCA (art. 12, VII, e § 1º): da abertura do cadastro das demandas à divulgação
 * do plano. As etapas seguem em ordem, e a divulgação acontece até o fim do exercício a que o plano se refere.
 */
final class CalendarioPca
{
    public const ETAPAS = [
        'inicio_demandas' => 'Início do cadastro das demandas',
        'fim_dfds' => 'Fim do cadastro dos DFDs',
        'fim_aprovacao' => 'Fim da aprovação dos DFDs',
        'fim_revisao' => 'Fim da revisão',
        'fim_consolidacao' => 'Fim da consolidação',
        'fim_divulgacao' => 'Fim da divulgação',
    ];

    /**
     * @param  array<string, ?string>  $datas  'Y-m-d' por etapa
     * @return list<string>
     */
    public static function motivos(array $datas, int $exercicio): array
    {
        $m = [];
        $anterior = null;

        foreach (self::ETAPAS as $chave => $rotulo) {
            $d = $datas[$chave] ?? null;
            if (! $d) {
                $m[] = "Informe a data: {$rotulo}.";

                continue;
            }
            if ($anterior && $d < $datas[$anterior]) {
                $m[] = sprintf('%s não pode ser antes de: %s.', $rotulo, mb_strtolower(self::ETAPAS[$anterior]));
            }
            $anterior = $chave;
        }

        if (($datas['fim_divulgacao'] ?? null) && $datas['fim_divulgacao'] > "{$exercicio}-12-31") {
            $m[] = "A divulgação do PCA {$exercicio} precisa acontecer até 31/12/{$exercicio}.";
        }

        return $m;
    }

    /**
     * Etapa em curso: a primeira cujo prazo ainda não passou. Antes do início, a etapa é o próprio início.
     *
     * @param  array<string, ?string>  $datas
     * @return ?array{chave: string, rotulo: string, prazo: string, encerrado: bool}
     */
    public static function etapaAtual(array $datas, string $hoje): ?array
    {
        $ultima = null;
        foreach (self::ETAPAS as $chave => $rotulo) {
            if (! ($d = $datas[$chave] ?? null)) {
                continue;
            }
            $ultima = ['chave' => $chave, 'rotulo' => $rotulo, 'prazo' => $d, 'encerrado' => true];
            if ($hoje <= $d) {
                return ['chave' => $chave, 'rotulo' => $rotulo, 'prazo' => $d, 'encerrado' => false];
            }
        }

        return $ultima;
    }
}
