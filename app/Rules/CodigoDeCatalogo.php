<?php

namespace App\Rules;

use App\Models\CatalogoItem;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Confere o código no catálogo CATMAT/CATSER, quando o catálogo do tipo está carregado. Código inativo não é aceito. */
class CodigoDeCatalogo implements ValidationRule
{
    public function __construct(private ?string $tipo) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || ! in_array($this->tipo, ['M', 'S'], true) || ! CatalogoItem::carregado($this->tipo)) {
            return;
        }

        $item = CatalogoItem::where('tipo', $this->tipo)->where('codigo', $value)->first();
        if (! $item) {
            $fail('Este código não consta no catálogo '.($this->tipo === 'M' ? 'CATMAT' : 'CATSER').'. Consulte o catálogo.');
        } elseif (! $item->ativo) {
            $fail('Este código está inativo no catálogo. Escolha o código vigente.');
        }
    }
}
