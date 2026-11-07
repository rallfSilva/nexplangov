<?php

namespace App\Models;

use App\Licitacao\Regras\DescricaoCatalogo;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Item do catálogo do órgão: descrição padronizada, atributos e imagem, ligado ao código CATMAT/CATSER (art. 19, II). */
#[Fillable(['orgao_id', 'tipo', 'codigo_catalogo', 'descricao', 'descricao_normalizada', 'unidade_fornecimento', 'atributos', 'especificacao', 'natureza_despesa',
    'imagem_caminho', 'imagem_hash', 'ativo', 'motivo_inativacao', 'criado_por'])]
class ItemCatalogoOrgao extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'itens_catalogo_orgao';

    public const TIPOS = ['M' => 'Material', 'S' => 'Serviço'];

    protected function casts(): array
    {
        return ['atributos' => 'array', 'ativo' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $i) => $i->descricao_normalizada = DescricaoCatalogo::normalizar($i->descricao));
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function scopeBuscar(Builder $q, string $termo): Builder
    {
        foreach (explode(' ', DescricaoCatalogo::normalizar($termo)) as $p) {
            if ($p !== '') {
                $q->where(fn ($w) => $w->where('descricao_normalizada', 'like', '%'.$p.'%')->orWhere('codigo_catalogo', 'like', $p.'%'));
            }
        }

        return $q;
    }

    /** Atributos em texto corrido, para levar à descrição do item no PCA ou no processo. */
    public function descricaoCompleta(): string
    {
        $attrs = collect($this->atributos ?? [])->map(fn ($a) => $a['nome'].': '.$a['valor'])->implode('; ');

        return $this->descricao.($attrs !== '' ? ' · '.$attrs : '');
    }

    protected function rotuloAuditoria(): string
    {
        return "Item do catálogo do órgão \"{$this->descricao}\"";
    }
}
