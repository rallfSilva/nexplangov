<?php

namespace App\Licitacao\Regras;

/**
 * No PNCP o PCA é publicado por unidade compradora (art. 12, § 1º, e art. 174): cada unidade com código no PNCP
 * tem o seu plano do exercício. O item vai para a unidade requisitante ou, se ela não tem código, para a superior
 * mais próxima que tenha. Item sem unidade ou sem superior com código vai para a unidade padrão do órgão.
 */
final class UnidadesPncpPca
{
    /**
     * Unidade que publica no PNCP o item pedido por $unidadeId: ela mesma ou a superior mais próxima com código.
     *
     * @param  array<int, array{parent: ?int, codigo: ?string}>  $unidades  indexado pelo id
     */
    public static function publicadora(array $unidades, ?int $unidadeId): ?int
    {
        $visitadas = [];

        while ($unidadeId !== null && isset($unidades[$unidadeId]) && ! isset($visitadas[$unidadeId])) {
            if (trim((string) $unidades[$unidadeId]['codigo']) !== '') {
                return $unidadeId;
            }
            $visitadas[$unidadeId] = true;
            $unidadeId = $unidades[$unidadeId]['parent'];
        }

        return null;
    }

    /**
     * Separa os itens do plano pela unidade que publica cada um.
     *
     * @param  array<int, array{parent: ?int, codigo: ?string}>  $unidades
     * @param  array<int, ?int>  $itens  id do item => unidade requisitante
     * @param  ?int  $padrao  unidade com código para os itens que não acham outra
     * @return array{grupos: array<int, list<int>>, sem_unidade: list<int>}
     */
    public static function agrupar(array $unidades, array $itens, ?int $padrao): array
    {
        $grupos = [];
        $sem = [];

        foreach ($itens as $item => $unidade) {
            $destino = self::publicadora($unidades, $unidade) ?? $padrao;
            if ($destino === null) {
                $sem[] = $item;
            } else {
                $grupos[$destino][] = $item;
            }
        }

        ksort($grupos);

        return ['grupos' => $grupos, 'sem_unidade' => $sem];
    }
}
