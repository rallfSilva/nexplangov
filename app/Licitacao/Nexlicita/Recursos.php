<?php

namespace App\Licitacao\Nexlicita;

use App\Licitacao\Enums\Modalidade;
use App\Licitacao\Regras\PrazosRecurso;
use App\Licitacao\RegraViolada;
use App\Models\Fornecedor;
use App\Models\ItemProcesso;
use App\Models\Processo;
use App\Models\Recurso;
use App\Models\RecursoContrarrazao;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Fase recursal (arts. 165 a 168). O agente registra o que os licitantes apresentam (na sessão ou por protocolo),
 * reconsidera ou mantém; a autoridade superior decide. Recurso pendente suspende o ato recorrido (art. 168).
 */
final class Recursos
{
    public function __construct(private PrazosRecurso $prazos) {}

    /** Intenção manifestada na sessão, sob pena de preclusão (art. 165, § 1º, I). */
    public function registrarIntencao(Processo $p, Fornecedor $f, string $tipo, string $motivo, ?ItemProcesso $item, User $u): Recurso
    {
        $motivos = [];

        if (Modalidade::from($p->modalidade)->ehContratacaoDireta()) {
            throw new RegraViolada(['Contratação direta não tem fase recursal de licitação.']);
        }
        if (! array_key_exists($tipo, Recurso::TIPOS)) {
            $motivos[] = 'Tipo de recurso inválido (art. 165, I).';
        }
        if ($p->situacao !== 'em_julgamento') {
            $motivos[] = 'A intenção de recurso é registrada durante a sessão, com o processo em julgamento.';
        } elseif ($p->sessaoEncerrada()) {
            $motivos[] = 'A sessão já foi encerrada: a intenção de recorrer precisava ser manifestada nela (art. 165, § 1º, I).';
        }
        if ($item && $item->processo_id !== $p->id) {
            $motivos[] = 'O item não é deste processo.';
        }
        if (! $p->itens()->whereHas('propostas', fn ($q) => $q->where('fornecedor_id', $f->id))->exists()) {
            $motivos[] = "{$f->razao_social} não apresentou proposta nesta licitação.";
        }
        if (mb_strlen(trim($motivo)) < 10) {
            $motivos[] = 'Resuma o motivo da intenção de recorrer (mínimo 10 caracteres).';
        }
        if ($motivos) {
            throw new RegraViolada($motivos);
        }

        return Recurso::create([
            'orgao_id' => $p->orgao_id, 'processo_id' => $p->id, 'item_id' => $item?->id, 'fornecedor_id' => $f->id,
            'tipo' => $tipo, 'situacao' => 'intencao', 'intencao_motivo' => trim($motivo), 'intencao_em' => now(), 'registrado_por' => $u->id,
        ]);
    }

    /** Razões em até 3 dias úteis da ata (art. 165, I). Abre o prazo das contrarrazões. */
    public function registrarRazoes(Recurso $r, string $texto): void
    {
        $motivos = [];

        if ($r->situacao !== 'intencao') {
            $motivos[] = 'As razões já foram apresentadas ou o recurso foi decidido.';
        } elseif (! $r->prazo_razoes) {
            $motivos[] = 'O prazo das razões começa quando a sessão é encerrada e a ata lavrada.';
        } elseif (! $this->prazos->tempestivo(now(), $r->prazo_razoes)) {
            $motivos[] = 'O prazo das razões terminou em '.$r->prazo_razoes->format('d/m/Y').': o recurso não é conhecido (art. 165, I).';
        }
        if (mb_strlen(trim($texto)) < 30) {
            $motivos[] = 'Registre as razões do recurso (mínimo 30 caracteres).';
        }
        if ($motivos) {
            throw new RegraViolada($motivos);
        }

        $fim = $this->prazos->contrarrazoes(now());
        $r->update([
            'situacao' => 'razoes', 'razoes' => trim($texto), 'razoes_em' => now(),
            'prazo_contrarrazoes' => $fim->toDateString(),
            'prazo_reconsideracao' => $this->prazos->reconsideracao($fim)->toDateString(),
        ]);
    }

    /** Contrarrazões de outro licitante, no mesmo prazo do recurso (art. 165, § 4º). */
    public function registrarContrarrazoes(Recurso $r, Fornecedor $f, string $texto, User $u): RecursoContrarrazao
    {
        $motivos = [];

        if ($r->situacao !== 'razoes') {
            $motivos[] = 'Só cabem contrarrazões depois das razões e antes da decisão.';
        } elseif (! $this->prazos->tempestivo(now(), $r->prazo_contrarrazoes)) {
            $motivos[] = 'O prazo das contrarrazões terminou em '.$r->prazo_contrarrazoes->format('d/m/Y').'.';
        }
        if ($f->id === $r->fornecedor_id) {
            $motivos[] = 'O próprio recorrente não apresenta contrarrazões.';
        }
        if ($r->contrarrazoes()->where('fornecedor_id', $f->id)->exists()) {
            $motivos[] = "{$f->razao_social} já apresentou contrarrazões.";
        }
        if (mb_strlen(trim($texto)) < 30) {
            $motivos[] = 'Registre as contrarrazões (mínimo 30 caracteres).';
        }
        if ($motivos) {
            throw new RegraViolada($motivos);
        }

        return $r->contrarrazoes()->create(['orgao_id' => $r->orgao_id, 'fornecedor_id' => $f->id, 'texto' => trim($texto), 'registrado_por' => $u->id]);
    }

    /**
     * O agente que praticou o ato reconsidera ou mantém, depois do prazo das contrarrazões (art. 165, § 2º).
     * Mantido, sobe à autoridade superior, que tem 10 dias úteis.
     */
    public function reconsiderar(Recurso $r, bool $reconsidera, string $fundamento, User $u): void
    {
        $motivos = [];

        if ($r->situacao !== 'razoes') {
            $motivos[] = 'O recurso não está na fase de reconsideração.';
        } elseif (! $r->prontoParaReconsiderar()) {
            $motivos[] = 'Aguarde o fim do prazo das contrarrazões ('.$r->prazo_contrarrazoes->format('d/m/Y').') para garantir o contraditório (art. 165, § 4º).';
        }
        if (mb_strlen(trim($fundamento)) < 30) {
            $motivos[] = 'Fundamente a decisão (mínimo 30 caracteres).';
        }
        if ($motivos) {
            throw new RegraViolada($motivos);
        }

        $r->update([
            'situacao' => $reconsidera ? 'reconsiderado' : 'superior',
            'reconsideracao' => $reconsidera ? 'reconsiderou' : 'manteve',
            'reconsideracao_texto' => trim($fundamento), 'reconsiderado_por' => $u->id, 'reconsiderado_em' => now(),
            'prazo_decisao' => $reconsidera ? null : $this->prazos->decisao(now())->toDateString(),
            'resultado' => $reconsidera ? 'provido' : null,
        ]);
    }

    /** Decisão final da autoridade superior (art. 165, § 2º), que não pode ser quem manteve o ato. */
    public function decidir(Recurso $r, string $resultado, string $fundamento, User $u): void
    {
        $motivos = [];

        if ($r->situacao !== 'superior') {
            $motivos[] = 'O recurso não foi encaminhado à autoridade superior.';
        }
        if (! array_key_exists($resultado, Recurso::RESULTADOS)) {
            $motivos[] = 'Resultado inválido.';
        }
        if (! $u->can('processos.autorizar')) {
            $motivos[] = 'Só a autoridade competente decide o recurso.';
        }
        if ($r->reconsiderado_por === $u->id) {
            $motivos[] = 'Quem manteve o ato não decide o recurso (art. 165, § 2º; segregação de funções, art. 7º, § 1º).';
        }
        if (mb_strlen(trim($fundamento)) < 30) {
            $motivos[] = 'Fundamente a decisão (mínimo 30 caracteres).';
        }
        if ($motivos) {
            throw new RegraViolada($motivos);
        }

        $r->update(['situacao' => 'decidido', 'resultado' => $resultado, 'decisao' => trim($fundamento), 'decidido_por' => $u->id, 'decidido_em' => now()]);
    }

    /** @return Collection<int, Recurso> */
    public function pendentes(Processo $p)
    {
        return $p->recursos()->get()->filter->pendente()->values();
    }
}
