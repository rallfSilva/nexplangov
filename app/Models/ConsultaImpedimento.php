<?php

namespace App\Models;

use App\Licitacao\Regras\ImpedimentoCadastral;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Consulta ao CEIS e ao CNEP feita pelo sistema (art. 161), com a análise de abrangência quando houver. */
#[Fillable(['orgao_id', 'fornecedor_id', 'user_id', 'consultado_em', 'situacao', 'registros', 'assinatura_registros', 'hash_resposta', 'erro', 'ressalva_justificativa', 'ressalva_por', 'ressalva_em'])]
class ConsultaImpedimento extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'consultas_impedimento';

    public const SITUACOES = ['regular' => 'Nada consta', 'sancionado' => 'Com sanção registrada', 'indisponivel' => 'Consulta indisponível'];

    protected function casts(): array
    {
        return ['consultado_em' => 'datetime', 'ressalva_em' => 'datetime', 'registros' => 'array'];
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function autorRessalva(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ressalva_por');
    }

    /** @return list<array{cadastro: string, tipo: string, orgao: string, inicio: ?string, fim: ?string}> */
    public function vigentes(): array
    {
        return ImpedimentoCadastral::vigentes($this->registros ?? [], today());
    }

    /** @return list<string> */
    public function motivos(): array
    {
        return ImpedimentoCadastral::motivos($this->vigentes(), $this->ressalva_em !== null);
    }

    protected function generoAuditoria(): string
    {
        return 'a';
    }

    protected function rotuloAuditoria(): string
    {
        return 'Consulta CEIS/CNEP do fornecedor '.$this->fornecedor?->razao_social;
    }
}
