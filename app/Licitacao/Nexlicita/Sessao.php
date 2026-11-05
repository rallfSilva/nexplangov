<?php

namespace App\Licitacao\Nexlicita;

use App\Integracoes\Transparencia\VerificadorImpedimento;
use App\Licitacao\Enums\CategoriaProcesso;
use App\Licitacao\Enums\CriterioJulgamento;
use App\Licitacao\Enums\Modalidade;
use App\Licitacao\Enums\ModoDisputa;
use App\Licitacao\Regras\Julgamento;
use App\Licitacao\RegraViolada;
use App\Models\Fornecedor;
use App\Models\ItemProcesso;
use App\Models\Lance;
use App\Models\Processo;
use App\Models\Proposta;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Condução da licitação: propostas, lances, desclassificação, habilitação e adjudicação.
 * Tudo acontece com o processo publicado ou em julgamento; a homologação continua em TramitacaoProcesso.
 */
final class Sessao
{
    public function __construct(private Julgamento $regras = new Julgamento) {}

    public function criterio(Processo $p): CriterioJulgamento
    {
        return CriterioJulgamento::from($p->criterio_julgamento);
    }

    public function registrarProposta(Processo $p, ItemProcesso $item, Fornecedor $f, float $valor, ?string $marca, User $u): Proposta
    {
        $this->exigirLicitacao($p, ['publicado', 'em_julgamento']);
        $this->exigirItem($p, $item);
        $this->exigirSessaoAberta($p);
        $motivos = [];

        if (! $f->ativo) {
            $motivos[] = "{$f->razao_social} está inativo no cadastro.";
        }
        if ($sancao = $f->impedidoEm()) {
            $motivos[] = "{$f->razao_social} está impedido de licitar até ".($sancao->fim?->format('d/m/Y') ?? 'decisão em contrário').' (art. 14, III, e art. 156, III e IV).';
        }
        if ($item->propostas()->where('fornecedor_id', $f->id)->exists()) {
            $motivos[] = "{$f->razao_social} já tem proposta neste item.";
        }
        if ($valor <= 0) {
            $motivos[] = 'Informe um valor maior que zero.';
        }
        if ($this->criterio($p) === CriterioJulgamento::MaiorDesconto && $valor >= 100) {
            $motivos[] = 'No maior desconto, informe o percentual abaixo de 100.';
        }
        if ($motivos) {
            throw new RegraViolada($motivos);
        }

        $situacao = 'classificada';
        $motivo = null;
        $estimado = (float) $item->valor_unitario_estimado;

        if ($this->criterio($p) === CriterioJulgamento::MenorPreco) {
            if ($this->regras->acimaDoOrcamento($valor, $estimado)) {
                [$situacao, $motivo] = ['desclassificada', 'Preço acima do orçamento estimado (art. 59, III).'];
            } elseif (in_array($p->categoria, [CategoriaProcesso::Obras->value, CategoriaProcesso::ServicosEngenharia->value], true) && $this->regras->inexequivelEngenharia($valor, $estimado)) {
                [$situacao, $motivo] = ['desclassificada', 'Valor abaixo de 75% do orçado em obra ou serviço de engenharia: inexequível (art. 59, § 4º).'];
            }
        }

        return Proposta::create([
            'orgao_id' => $p->orgao_id, 'processo_id' => $p->id, 'item_id' => $item->id, 'fornecedor_id' => $f->id,
            'valor' => $valor, 'marca_modelo' => $marca, 'situacao' => $situacao, 'motivo' => $motivo,
            'me_epp' => in_array($f->porte, ['ME', 'EPP'], true), 'registrada_por' => $u->id,
        ]);
    }

    public function registrarLance(Proposta $prop, float $valor, float $intervaloMinimo, User $u): Lance
    {
        $p = $prop->processo;
        $this->exigirLicitacao($p, ['em_julgamento']);
        $this->exigirSessaoAberta($p);

        if (! in_array($p->modo_disputa, [1, 3], true)) {
            throw new RegraViolada(['Este processo não tem disputa aberta (modo de disputa: '.ModoDisputa::from($p->modo_disputa)->rotulo().', art. 56).']);
        }
        if ($prop->situacao !== 'classificada') {
            throw new RegraViolada(['Só propostas classificadas dão lance.']);
        }
        if ($prop->item->adjudicado_em) {
            throw new RegraViolada(['O item já foi adjudicado.']);
        }

        $criterio = $this->criterio($p);
        $melhor = $this->melhorVigente($prop->item, $criterio);

        if (! $this->regras->lanceValido($valor, $melhor, $intervaloMinimo, $criterio)) {
            $msg = $melhor === null ? 'Lance inválido.' : 'O lance precisa ser melhor que o melhor valor vigente ('.number_format($melhor, 4, ',', '.').')'.($intervaloMinimo > 0 ? ' com diferença mínima de '.number_format($intervaloMinimo, 4, ',', '.') : '').'.';
            throw new RegraViolada([$msg]);
        }

        return Lance::create(['orgao_id' => $p->orgao_id, 'processo_id' => $p->id, 'item_id' => $prop->item_id, 'proposta_id' => $prop->id, 'valor' => $valor, 'user_id' => $u->id]);
    }

    public function melhorVigente(ItemProcesso $item, CriterioJulgamento $criterio): ?float
    {
        $valores = $item->propostas()->where('situacao', 'classificada')->get()->map(fn (Proposta $x) => $x->valorVigente());

        return $valores->isEmpty() ? null : ($this->regras->maiorEhMelhor($criterio) ? $valores->max() : $valores->min());
    }

    /**
     * Classificação do item com os valores vigentes (lances incluídos).
     *
     * @return array{ordem: list<Proposta>, empatadas: list<int>, empate_ficto: list<int>}
     */
    public function classificacao(ItemProcesso $item): array
    {
        $p = $item->processo;
        $criterio = $this->criterio($p);
        $props = $item->propostas()->with('fornecedor')->get();
        $lista = $props->map(fn (Proposta $x) => ['id' => $x->id, 'valor' => $x->valorVigente(), 'me_epp' => $x->me_epp, 'situacao' => $x->situacao === 'vencedora' ? 'classificada' : $x->situacao])->all();

        $porId = $props->keyBy('id');
        $ordem = array_map(fn ($id) => $porId[$id], $this->regras->classificar($lista, $criterio));
        $pregao = in_array($p->modalidade, [Modalidade::PregaoEletronico->value, Modalidade::PregaoPresencial->value], true);

        return [
            'ordem' => $ordem,
            'empatadas' => count($e = $this->regras->empatadas($lista, $criterio)) > 1 ? $e : [],
            'empate_ficto' => $this->regras->empateFicto($lista, $criterio, $pregao),
        ];
    }

    public function desclassificar(Proposta $prop, string $motivo): void
    {
        $this->exigirLicitacao($prop->processo, ['em_julgamento']);
        $this->exigirMotivo($motivo, 'da desclassificação');

        if ($prop->item->adjudicado_em && $prop->item->proposta_id === $prop->id) {
            throw new RegraViolada(['A proposta já foi adjudicada. Revogue a adjudicação antes.']);
        }

        $prop->update(['situacao' => 'desclassificada', 'motivo' => trim($motivo)]);
    }

    public function habilitar(Proposta $prop): void
    {
        $this->exigirLicitacao($prop->processo, ['em_julgamento']);

        if ($prop->situacao !== 'classificada') {
            throw new RegraViolada(['Só se habilita proposta classificada.']);
        }
        if ($sancao = $prop->fornecedor->impedidoEm()) {
            throw new RegraViolada(["{$prop->fornecedor->razao_social} está impedido de contratar até ".($sancao->fim?->format('d/m/Y') ?? 'decisão em contrário').' (art. 156). Inabilite a proposta.']);
        }

        $prop->update(['habilitacao' => 'habilitado', 'habilitacao_motivo' => null]);
    }

    public function inabilitar(Proposta $prop, string $motivo): void
    {
        $this->exigirLicitacao($prop->processo, ['em_julgamento']);
        $this->exigirMotivo($motivo, 'da inabilitação');

        if ($prop->item->adjudicado_em && $prop->item->proposta_id === $prop->id) {
            throw new RegraViolada(['A proposta já foi adjudicada. Revogue a adjudicação antes.']);
        }

        // A proposta sai da disputa e a seguinte passa a ser a primeira (arts. 62 a 70).
        $prop->update(['habilitacao' => 'inabilitado', 'habilitacao_motivo' => trim($motivo), 'situacao' => 'recusada', 'motivo' => 'Inabilitada: '.trim($motivo)]);
    }

    public function registrarDesempate(Proposta $prop, string $criterio, string $justificativa): void
    {
        if (! array_key_exists($criterio, Julgamento::DESEMPATE)) {
            throw new RegraViolada(['Critério de desempate inválido (art. 60).']);
        }
        $this->exigirMotivo($justificativa, 'do desempate');
        $prop->update(['desempate_criterio' => $criterio, 'desempate_justificativa' => trim($justificativa)]);
    }

    /** Adjudicação (art. 71, IV): atribui o objeto do item ao vencedor. */
    public function adjudicar(ItemProcesso $item, Proposta $prop, User $u, ?string $empateFictoTratado = null): void
    {
        $p = $item->processo;
        $this->exigirLicitacao($p, ['em_julgamento']);
        $motivos = [];

        if ($prop->item_id !== $item->id) {
            throw new RegraViolada(['A proposta não é deste item.']);
        }
        if ($item->adjudicado_em) {
            $motivos[] = 'O item já foi adjudicado.';
        }

        $c = $this->classificacao($item);
        $primeira = $c['ordem'][0] ?? null;

        if ($prop->situacao !== 'classificada') {
            $motivos[] = 'Só se adjudica proposta classificada.';
        } elseif ($primeira?->id !== $prop->id && ! (in_array($prop->id, $c['empatadas'], true))) {
            $motivos[] = 'A proposta não é a primeira colocada. A adjudicação segue a classificação.';
        }
        if ($prop->habilitacao !== 'habilitado') {
            $motivos[] = 'A proposta precisa estar habilitada (arts. 62 a 70).';
        }
        if ($sancao = $prop->fornecedor->impedidoEm()) {
            $motivos[] = "{$prop->fornecedor->razao_social} está impedido de contratar até ".($sancao->fim?->format('d/m/Y') ?? 'decisão em contrário').' (art. 156).';
        }
        array_push($motivos, ...app(VerificadorImpedimento::class)->motivos($prop->fornecedor)); // CEIS e CNEP (art. 161)
        if (count($c['empatadas']) > 1 && in_array($prop->id, $c['empatadas'], true) && ! $prop->desempate_criterio) {
            $motivos[] = 'Há propostas empatadas: registre o critério de desempate aplicado (art. 60).';
        }
        // Recurso pendente suspende o ato recorrido (art. 168): o item só é adjudicado depois da decisão.
        $pendentes = $p->recursos()->where(fn ($q) => $q->whereNull('item_id')->orWhere('item_id', $item->id))->get()->filter->pendente();
        if ($pendentes->isNotEmpty()) {
            $motivos[] = 'Há recurso pendente sobre este item: a adjudicação espera a decisão (art. 168).';
        }
        if ($c['empate_ficto'] !== [] && ! $prop->me_epp && trim((string) $empateFictoTratado) === '') {
            $motivos[] = 'Há ME/EPP em empate ficto com a melhor proposta: ofereça a elas a chance de cobrir (registre um lance) ou informe como o empate foi tratado (art. 4º).';
        }
        if ($motivos) {
            throw new RegraViolada($motivos);
        }

        DB::transaction(function () use ($item, $prop, $u) {
            $item->propostas()->where('situacao', 'vencedora')->update(['situacao' => 'classificada']);
            $prop->update(['situacao' => 'vencedora']);
            $item->update([
                'fornecedor_vencedor_id' => $prop->fornecedor_id, 'valor_unitario_homologado' => $prop->valorVigente(), 'situacao' => 'pendente',
                'proposta_id' => $prop->id, 'adjudicado_em' => now(), 'adjudicado_por' => $u->id,
            ]);
        });
    }

    public function revogarAdjudicacao(ItemProcesso $item, string $motivo): void
    {
        $this->exigirLicitacao($item->processo, ['em_julgamento']);
        $this->exigirMotivo($motivo, 'da revogação da adjudicação');

        DB::transaction(function () use ($item) {
            $item->propostas()->where('situacao', 'vencedora')->update(['situacao' => 'classificada']);
            $item->update(['fornecedor_vencedor_id' => null, 'valor_unitario_homologado' => null, 'proposta_id' => null, 'adjudicado_em' => null, 'adjudicado_por' => null]);
        });
    }

    private function exigirLicitacao(Processo $p, array $situacoes): void
    {
        if (Modalidade::from($p->modalidade)->ehContratacaoDireta()) {
            throw new RegraViolada(['Contratação direta não tem sessão de licitação. O fornecedor é informado no resultado.']);
        }
        if (! in_array($p->situacao, $situacoes, true)) {
            throw new RegraViolada(['Esta ação só vale com o processo '.(in_array('publicado', $situacoes, true) ? 'publicado ou em julgamento' : 'em julgamento').'.']);
        }
    }

    /** Depois de lavrada a ata, não entram propostas nem lances novos; habilitação e adjudicação seguem (efeito dos recursos). */
    private function exigirSessaoAberta(Processo $p): void
    {
        if ($p->sessaoEncerrada()) {
            throw new RegraViolada(['A sessão pública foi encerrada e a ata lavrada. Para registrar proposta ou lance, reabra a sessão com justificativa.']);
        }
    }

    private function exigirItem(Processo $p, ItemProcesso $item): void
    {
        if ($item->processo_id !== $p->id) {
            throw new RegraViolada(['O item não é deste processo.']);
        }
        if ($item->adjudicado_em) {
            throw new RegraViolada(['O item já foi adjudicado.']);
        }
    }

    private function exigirMotivo(string $motivo, string $de): void
    {
        if (mb_strlen(trim($motivo)) < 10) {
            throw new RegraViolada(["Informe o motivo {$de} (mínimo 10 caracteres)."]);
        }
    }
}
