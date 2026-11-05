<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Anotação interna de rascunho entre os servidores que atuam no processo. Serve de apoio à instrução e não
 * compõe os autos: não entra no checklist, no dossiê, nas exportações nem no PNCP.
 */
#[Fillable(['orgao_id', 'processo_id', 'user_id', 'texto'])]
class AnotacaoProcesso extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'anotacoes_processo';

    public const PERMISSOES_ESCRITA = ['processos.gerenciar', 'processos.parecer', 'processos.autorizar', 'licitacao.conduzir'];

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function podeEscrever(User $u): bool
    {
        return $u->canAny(self::PERMISSOES_ESCRITA);
    }

    protected function generoAuditoria(): string
    {
        return 'a';
    }

    protected function rotuloAuditoria(): string
    {
        return 'Anotação interna do processo';
    }
}
