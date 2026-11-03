<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Item do catálogo nacional de materiais (CATMAT) ou serviços (CATSER). Lei 14.133/2021, art. 19, I e art. 174, § 3º, V. */
#[Fillable(['tipo', 'codigo', 'descricao', 'classe_codigo', 'classe_nome', 'unidade_padrao', 'ativo', 'fonte'])]
class CatalogoItem extends Model
{
    protected $table = 'catalogo_itens';

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    /** Busca por código (começa com) ou por palavras da descrição (todas precisam aparecer). */
    public function scopeBuscar(Builder $q, string $termo, ?string $tipo = null): Builder
    {
        $termo = trim($termo);
        $q->when($tipo, fn ($q) => $q->where('tipo', $tipo));

        if (ctype_digit($termo)) {
            return $q->where('codigo', 'like', $termo.'%');
        }

        foreach (preg_split('/\s+/', $termo, -1, PREG_SPLIT_NO_EMPTY) as $palavra) {
            $q->where('descricao', 'ilike', '%'.addcslashes($palavra, '%_\\').'%');
        }

        return $q;
    }

    /** Há catálogo carregado para o tipo? Sem ele não dá para conferir o código. */
    public static function carregado(string $tipo): bool
    {
        return static::where('tipo', $tipo)->exists();
    }
}
