<?php

namespace App\Licitacao\Pca;

use App\Licitacao\Enums\CategoriaProcesso;
use App\Rules\CodigoDeCatalogo;
use Illuminate\Validation\Rule;

/**
 * Regras de validação de um item do PCA (art. 12, VII), usadas na tela e na importação de planilha,
 * para que um item importado passe pelas mesmas conferências de um item digitado.
 */
final class RegrasItemPca
{
    /** @return array<string, list<mixed>> */
    public static function regras(int $orgaoId, ?string $materialServico): array
    {
        return [
            'material_servico' => ['required', Rule::in(['M', 'S'])],
            'categoria' => ['required', Rule::enum(CategoriaProcesso::class)],
            'codigo_catalogo' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/', new CodigoDeCatalogo($materialServico)],
            'descricao' => ['required', 'string', 'max:2000'],
            'unidade_fornecimento' => ['required', 'string', 'max:30'],
            'quantidade' => ['required', 'numeric', 'gt:0'],
            'valor_unitario_estimado' => ['required', 'numeric', 'gt:0'],
            'unidade_id' => ['nullable', 'integer', Rule::exists('unidades', 'id')->where('orgao_id', $orgaoId)],
            'data_desejada' => ['nullable', 'date'],
            'prioridade' => ['required', Rule::in([1, 2, 3])],
            'natureza_despesa' => ['nullable', 'string', 'max:40'],
        ];
    }

    /** @return array<string, string> */
    public static function mensagens(): array
    {
        return ['codigo_catalogo.regex' => 'Use só os números do código CATMAT ou CATSER.'];
    }
}
