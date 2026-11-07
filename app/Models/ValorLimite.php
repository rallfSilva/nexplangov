<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Valor da Lei 14.133 atualizado por decreto (art. 182). Vale para todos os órgãos. */
#[Fillable(['ano', 'dispositivo', 'valor', 'ato_normativo', 'conferido'])]
class ValorLimite extends Model
{
    use Auditable;

    protected $table = 'valores_limite';

    public const DISPOSITIVOS = [
        'art75_I' => 'Art. 75, I: dispensa para obras e serviços de engenharia',
        'art75_II' => 'Art. 75, II: dispensa para outros serviços e compras',
        'art6_XXII' => 'Art. 6º, XXII: obras, serviços e fornecimentos de grande vulto',
    ];

    protected function casts(): array
    {
        return ['valor' => 'decimal:2', 'conferido' => 'boolean'];
    }

    protected function rotuloAuditoria(): string
    {
        return "Valor limite {$this->dispositivo}/{$this->ano}";
    }
}
