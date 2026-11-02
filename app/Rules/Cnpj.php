<?php

namespace App\Rules;

use App\Support\Documento;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Cnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Documento::cnpjValido((string) $value)) {
            $fail('CNPJ inválido. Confira os dígitos.');
        }
    }
}
