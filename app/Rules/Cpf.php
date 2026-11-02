<?php

namespace App\Rules;

use App\Support\Documento;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Cpf implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Documento::cpfValido((string) $value)) {
            $fail('CPF inválido. Confira os dígitos.');
        }
    }
}
