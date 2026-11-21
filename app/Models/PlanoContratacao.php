<?php

namespace App\Models;

use App\Licitacao\Regras\CalendarioPca;
use App\Licitacao\Regras\ConsolidacaoDfd;
use App\Licitacao\Regras\UnidadesPncpPca;
use App\Licitacao\Regras\VersoesPca;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Plano de Contratações Anual (art. 12, VII). Depois de aprovado, só muda por revisão registrada. */
#[Fillable(['orgao_id', 'ano', 'situacao', 'observacoes', 'abrangencia', 'possui_objetivo_estrategico', 'objetivo_estrategico', 'tipo_consolidacao', 'unidade_responsavel_id', 'nivel_detalhamento',
    'inicio_demandas', 'fim_dfds', 'fim_aprovacao', 'fim_revisao', 'fim_consolidacao', 'fim_divulgacao'])]
class PlanoContratacao extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'planos_contratacao';

    public const SITUACOES = ['rascunho' => 'Em elaboração', 'em_aprovacao' => 'Em aprovação', 'aprovado' => 'Aprovado'];

    public const ABRANGENCIAS = ['orgao' => 'Órgão todo', 'unidades' => 'Unidades participantes'];

    public const TIPOS_CONSOLIDACAO = ['unidade' => 'Por unidade', 'setor' => 'Por setor'];

    public const NIVEIS_DETALHAMENTO = ['item' => 'Item do catálogo (código obrigatório)', 'classe' => 'Classe do catálogo', 'livre' => 'Descrição livre'];

    protected function casts(): array
    {
        return ['aprovado_em' => 'datetime', 'revisao_aberta_em' => 'datetime', 'versao_atual' => 'integer', 'possui_objetivo_estrategico' => 'boolean',
            'inicio_demandas' => 'date', 'fim_dfds' => 'date', 'fim_aprovacao' => 'date', 'fim_revisao' => 'date', 'fim_consolidacao' => 'date', 'fim_divulgacao' => 'date'];
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ItemPca::class)->orderBy('numero');
    }

    public function aprovador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprovado_por');
    }

    public function editavel(): bool
    {
        return $this->situacao !== 'aprovado';
    }

    public function importacoes(): HasMany
    {
        return $this->hasMany(ImportacaoPca::class)->latest();
    }

    public function versoes(): HasMany
    {
        return $this->hasMany(VersaoPca::class)->orderByDesc('numero');
    }

    public function versaoVigente(): ?VersaoPca
    {
        return $this->versao_atual > 0 ? $this->versoes()->where('numero', $this->versao_atual)->first() : null;
    }

    public function autorRevisao(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisao_aberta_por');
    }

    /** Revisão aberta sobre um plano já aprovado: os itens podem mudar até a nova aprovação. */
    public function emRevisao(): bool
    {
        return $this->revisao_aberta_em !== null;
    }

    /** Próximo número de item: nunca reaproveita número já usado no plano ou em versão anterior. */
    public function proximoNumeroItem(): int
    {
        $usados = $this->itens()->pluck('numero')->all();
        foreach ($this->versoes()->get(['itens']) as $v) {
            $usados = [...$usados, ...array_column($v->itens, 'numero')];
        }

        return VersoesPca::proximoNumero(array_map('intval', $usados));
    }

    /** @return list<array<string, mixed>> cópia dos itens atuais, com os campos guardados na versão */
    public function copiaDosItens(): array
    {
        return $this->itens()->get()->map(function (ItemPca $i) {
            $linha = [];
            foreach (VersaoPca::CAMPOS_ITEM as $c) {
                $v = $i->getAttribute($c);
                $linha[$c] = $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;
            }

            return $linha;
        })->values()->all();
    }

    public function participantes(): BelongsToMany
    {
        return $this->belongsToMany(Unidade::class, 'pca_participantes')->withPivot('orgao_id')->orderBy('sigla');
    }

    public function unidadeResponsavel(): BelongsTo
    {
        return $this->belongsTo(Unidade::class, 'unidade_responsavel_id');
    }

    /** @return array<string, ?string> datas do calendário de elaboração, para a regra CalendarioPca */
    public function calendario(): array
    {
        return collect(CalendarioPca::ETAPAS)->mapWithKeys(fn ($r, $c) => [$c => $this->getAttribute($c)?->format('Y-m-d')])->all();
    }

    /** Unidades que lançam DFD no plano: as participantes ou, no plano do órgão todo, as unidades ativas. */
    public function unidadesDoPlano(): Collection
    {
        return $this->abrangencia === 'unidades'
            ? $this->participantes()->get()
            : Unidade::where('orgao_id', $this->orgao_id)->where('ativo', true)->whereNull('parent_id')->orderBy('sigla')->get();
    }

    /** Unidade em que um DFD da unidade informada entra no plano (art. 12, VII), conforme o tipo de consolidação. */
    public function unidadeConsolidadora(?int $unidadeId): ?int
    {
        return ConsolidacaoDfd::unidadeConsolidadora($this->mapaPais(), $this->idsParticipantes(), $unidadeId, $this->tipo_consolidacao ?? 'unidade');
    }

    /** A unidade pode lançar DFD neste plano? No plano por unidades, só as participantes e os setores delas. */
    public function aceitaUnidade(?int $unidadeId): bool
    {
        return $unidadeId !== null && ($this->abrangencia !== 'unidades' || ConsolidacaoDfd::participanteDe($this->mapaPais(), $this->idsParticipantes(), $unidadeId) !== null);
    }

    /** @return array<int, ?int> */
    public function mapaPais(): array
    {
        return Unidade::where('orgao_id', $this->orgao_id)->pluck('parent_id', 'id')->all();
    }

    /** @return list<int> vazio no plano do órgão todo */
    public function idsParticipantes(): array
    {
        return $this->abrangencia === 'unidades' ? $this->participantes()->pluck('unidades.id')->map(fn ($i) => (int) $i)->all() : [];
    }

    public function pcasUnidade(): HasMany
    {
        return $this->hasMany(PcaUnidade::class);
    }

    /**
     * Itens separados pela unidade que publica cada um no PNCP (art. 12, § 1º). A unidade padrão é a primeira
     * do órgão com código no PNCP.
     *
     * @return array{grupos: array<int, list<int>>, sem_unidade: list<int>}
     */
    public function gruposPncp(): array
    {
        $unidades = Unidade::where('orgao_id', $this->orgao_id)->orderBy('id')->get(['id', 'parent_id', 'codigo_unidade_pncp']);
        $mapa = $unidades->mapWithKeys(fn (Unidade $u) => [$u->id => ['parent' => $u->parent_id, 'codigo' => $u->codigo_unidade_pncp]])->all();
        $padrao = $unidades->first(fn (Unidade $u) => trim((string) $u->codigo_unidade_pncp) !== '')?->id;

        return UnidadesPncpPca::agrupar($mapa, $this->itens()->pluck('unidade_id', 'id')->all(), $padrao);
    }

    public function valorTotal(): float
    {
        return (float) $this->itens()->sum('valor_total_estimado');
    }

    protected function rotuloAuditoria(): string
    {
        return 'PCA '.$this->ano;
    }
}
