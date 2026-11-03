<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Recurso administrativo na licitação (arts. 165 a 168). A intenção é manifestada na sessão; as razões
 * vêm em 3 dias úteis da ata, as contrarrazões em 3 dias úteis da interposição. O agente reconsidera ou
 * mantém em 3 dias úteis e a autoridade decide em 10 dias úteis. Enquanto houver recurso pendente,
 * o ato recorrido fica suspenso (art. 168): sem adjudicação do item nem homologação.
 */
#[Fillable(['orgao_id', 'processo_id', 'item_id', 'fornecedor_id', 'tipo', 'situacao', 'intencao_motivo', 'intencao_em', 'registrado_por', 'prazo_razoes', 'razoes', 'razoes_em', 'prazo_contrarrazoes', 'prazo_reconsideracao', 'reconsideracao', 'reconsideracao_texto', 'reconsiderado_por', 'reconsiderado_em', 'prazo_decisao', 'resultado', 'decisao', 'decidido_por', 'decidido_em'])]
class Recurso extends Model
{
    use Auditable, BelongsToOrgao;

    public const TIPOS = [
        'julgamento' => 'Julgamento das propostas',
        'habilitacao' => 'Habilitação ou inabilitação',
        'revogacao_anulacao' => 'Anulação ou revogação',
    ];

    public const SITUACOES = [
        'intencao' => 'Intenção registrada',
        'razoes' => 'Razões apresentadas',
        'superior' => 'Com a autoridade superior',
        'reconsiderado' => 'Reconsiderado pelo agente',
        'decidido' => 'Decidido',
        'sem_razoes' => 'Não conhecido (sem razões no prazo)',
    ];

    public const RESULTADOS = ['provido' => 'Provido', 'parcial' => 'Provido em parte', 'improvido' => 'Não provido'];

    protected function casts(): array
    {
        return [
            'intencao_em' => 'datetime', 'razoes_em' => 'datetime', 'reconsiderado_em' => 'datetime', 'decidido_em' => 'datetime',
            'prazo_razoes' => 'date', 'prazo_contrarrazoes' => 'date', 'prazo_reconsideracao' => 'date', 'prazo_decisao' => 'date',
        ];
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemProcesso::class, 'item_id');
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function contrarrazoes(): HasMany
    {
        return $this->hasMany(RecursoContrarrazao::class);
    }

    /** Situação considerando o tempo: intenção sem razões depois do prazo deixa de ser conhecida. */
    public function situacaoAtual(): string
    {
        if ($this->situacao === 'intencao' && $this->prazo_razoes !== null && $this->prazo_razoes->lt(today())) {
            return 'sem_razoes';
        }

        return $this->situacao;
    }

    /** Ainda suspende o ato recorrido (art. 168). */
    public function pendente(): bool
    {
        return in_array($this->situacaoAtual(), ['intencao', 'razoes', 'superior'], true);
    }

    /** Fase em que o agente pode decidir sobre a reconsideração: razões apresentadas e prazo de contrarrazões vencido. */
    public function prontoParaReconsiderar(): bool
    {
        return $this->situacao === 'razoes' && $this->prazo_contrarrazoes !== null && $this->prazo_contrarrazoes->lt(today());
    }

    protected function rotuloAuditoria(): string
    {
        return "Recurso #{$this->id} do processo #{$this->processo_id}";
    }
}
