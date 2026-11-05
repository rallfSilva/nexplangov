<?php

namespace App\Models;

use App\Licitacao\Regras\LivroOcorrencias;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Anotação do livro de ocorrências do contrato (art. 117, § 1º). Inalterável, também no banco. */
#[Fillable(['orgao_id', 'contrato_id', 'numero', 'user_id', 'autor_nome', 'autor_papel', 'tipo', 'ocorrida_em', 'descricao', 'providencias', 'prazo_regularizacao', 'retifica_id', 'hash_anterior', 'hash', 'registrada_em'])]
class OcorrenciaContrato extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'ocorrencias_contrato';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['ocorrida_em' => 'date', 'prazo_regularizacao' => 'date', 'registrada_em' => 'datetime', 'numero' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('O livro de ocorrências não se altera. Registre uma retificação.'));
        static::deleting(fn () => throw new LogicException('O livro de ocorrências não se apaga. Registre uma retificação.'));
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function retifica(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retifica_id');
    }

    public function tipoRotulo(): string
    {
        return LivroOcorrencias::TIPOS[$this->tipo] ?? $this->tipo;
    }

    /** Campos que entram no hash, na forma em que ficam gravados. */
    public function camposDoHash(): array
    {
        return [
            'contrato_id' => $this->contrato_id, 'numero' => (int) $this->numero, 'autor_nome' => $this->autor_nome, 'autor_papel' => $this->autor_papel, 'tipo' => $this->tipo,
            'ocorrida_em' => $this->ocorrida_em?->toDateString(), 'descricao' => $this->descricao, 'providencias' => $this->providencias,
            'prazo_regularizacao' => $this->prazo_regularizacao?->toDateString(), 'retifica_id' => $this->retifica_id, 'registrada_em' => $this->registrada_em?->toIso8601String(),
        ];
    }

    protected function generoAuditoria(): string
    {
        return 'a';
    }

    protected function rotuloAuditoria(): string
    {
        return "Anotação {$this->numero} do livro de ocorrências";
    }
}
