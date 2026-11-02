<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Database\Factories\UnidadeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['orgao_id', 'parent_id', 'sigla', 'nome', 'tipo', 'chefe_user_id', 'email', 'telefone', 'codigo_unidade_pncp', 'ativo'])]
class Unidade extends Model
{
    /** @use HasFactory<UnidadeFactory> */
    use Auditable, BelongsToOrgao, HasFactory;

    protected $table = 'unidades';

    /** @var array<string, string> */
    public const TIPOS = [
        'gabinete' => 'Gabinete',
        'secretaria_adjunta' => 'Secretaria adjunta',
        'diretoria' => 'Diretoria',
        'coordenadoria' => 'Coordenadoria',
        'departamento' => 'Departamento',
        'divisao' => 'Divisão',
        'nucleo' => 'Núcleo',
        'assessoria' => 'Assessoria',
        'comissao' => 'Comissão',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
        ];
    }

    public function superior(): BelongsTo
    {
        return $this->belongsTo(Unidade::class, 'parent_id');
    }

    public function subordinadas(): HasMany
    {
        return $this->hasMany(Unidade::class, 'parent_id')->orderBy('sigla');
    }

    public function chefe(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chefe_user_id');
    }

    /**
     * IDs desta unidade e de todas as subordinadas, em qualquer nível.
     *
     * @return list<int>
     */
    public function idsDescendentes(): array
    {
        $ids = [];
        $fila = [$this->id];

        while ($fila !== []) {
            $filhas = static::whereIn('parent_id', $fila)->pluck('id')->all();
            $filhas = array_values(array_diff($filhas, $ids));
            $ids = array_merge($ids, $filhas);
            $fila = $filhas;
        }

        return $ids;
    }

    protected function generoAuditoria(): string
    {
        return 'a';
    }

    protected function rotuloAuditoria(): string
    {
        return 'Unidade '.$this->sigla;
    }
}
