<?php

namespace App\Licitacao\Regras;

/**
 * Previsão da contratação no Plano de Contratações Anual (art. 12, VII, e art. 18, § 1º, II, da Lei 14.133).
 *
 * Ao abrir um processo, o sistema cruza a demanda com o PCA vigente do exercício. Se a contratação não
 * estiver prevista, o processo só abre com justificativa formal, que fica nos autos.
 */
final class PrevisaoPca
{
    public const JUSTIFICATIVA_MINIMA = 30;

    /**
     * Motivos que impedem abrir o processo. Lista vazia: pode abrir.
     *
     * @param  bool  $temItem  o processo aponta um item do PCA
     * @param  bool  $planoVigente  o item pertence a um plano já aprovado (versão vigente)
     * @param  int|null  $anoPlano  exercício do plano do item
     * @return list<string>
     */
    public static function motivos(bool $temItem, bool $planoVigente, ?int $anoPlano, int $anoProcesso, ?string $justificativa): array
    {
        if ($temItem) {
            $motivos = [];

            if (! $planoVigente) {
                $motivos[] = 'O item escolhido é de um PCA ainda não aprovado. Aguarde a aprovação da autoridade ou justifique a contratação fora do plano (art. 12, VII).';
            }

            if ($anoPlano !== null && $anoPlano !== $anoProcesso) {
                $motivos[] = "O item escolhido é do PCA de {$anoPlano}, e o processo é de {$anoProcesso}. Escolha um item do plano do mesmo exercício (art. 12, VII).";
            }

            return $motivos;
        }

        if (! self::justificativaValida($justificativa)) {
            return [sprintf(
                'Esta contratação não está prevista no PCA de %d. Escolha o item do plano ou escreva uma justificativa com pelo menos %d caracteres para abrir o processo fora do plano (art. 12, VII, e art. 18, § 1º, II).',
                $anoProcesso, self::JUSTIFICATIVA_MINIMA,
            )];
        }

        return [];
    }

    public static function justificativaValida(?string $texto): bool
    {
        return mb_strlen(trim((string) $texto)) >= self::JUSTIFICATIVA_MINIMA;
    }

    /**
     * Cruzamento automático do objeto com os itens do PCA: devolve os itens com mais palavras em comum,
     * do mais parecido ao menos parecido. Serve para sugerir; quem escolhe é o servidor.
     *
     * @param  iterable<array{id: int, descricao: string}>  $itens
     * @return list<int> ids dos itens sugeridos
     */
    public static function sugerir(string $objeto, iterable $itens, int $limite = 3): array
    {
        $alvo = self::palavras($objeto);

        if ($alvo === []) {
            return [];
        }

        $pontos = [];
        foreach ($itens as $item) {
            $comuns = count(array_intersect($alvo, self::palavras($item['descricao'])));

            if ($comuns > 0) {
                $pontos[$item['id']] = $comuns;
            }
        }

        arsort($pontos);

        return array_slice(array_map('intval', array_keys($pontos)), 0, $limite);
    }

    /** @return list<string> palavras de 4 letras ou mais, sem acento e em minúsculas, sem repetição */
    public static function palavras(string $texto): array
    {
        $texto = mb_strtolower(trim($texto));
        $texto = strtr($texto, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);
        $partes = preg_split('/[^a-z0-9]+/', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $vazias = ['para', 'com', 'sem', 'das', 'dos', 'pela', 'pelo', 'pelas', 'pelos', 'aquisicao', 'contratacao', 'servico', 'servicos', 'empresa', 'especializada', 'fornecimento', 'prestacao', 'atender', 'demanda', 'secretaria', 'municipal', 'diversos', 'diversas'];

        return array_values(array_unique(array_filter($partes, fn ($p) => mb_strlen($p) >= 4 && ! in_array($p, $vazias, true))));
    }
}
