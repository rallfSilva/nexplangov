<?php

namespace App\Rules;

use App\Licitacao\Regras\DescricaoCatalogo;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Barra termo vago na descrição de item do PCA ou do processo (art. 40, § 1º, I). */
class DescricaoNaoVaga implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ($vagos = DescricaoCatalogo::termosVagos($value))) {
            $fail('A descrição tem termo vago ('.implode(', ', $vagos).'). Especifique o item ou use o catálogo do órgão (art. 40, § 1º, I).');
        }
    }
}
