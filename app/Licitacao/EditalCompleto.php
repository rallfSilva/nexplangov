<?php

namespace App\Licitacao;

use App\Licitacao\Enums\Modalidade;
use App\Licitacao\Enums\ModoDisputa;
use App\Licitacao\Regras\MontagemEdital;
use App\Models\DocumentoProcesso;
use App\Models\ModeloDocumento;
use App\Models\Processo;
use App\Support\Parametros;
use App\Support\Pdf;
use Illuminate\Support\Collection;

/**
 * "Gerar edital completo": escolhe o modelo da modalidade, preenche com os dados do processo, do ETP, do TR e da
 * minuta do contrato, e junta o edital com os anexos num caderno PDF único. Continua sendo minuta: nada é salvo
 * sem o servidor revisar, e o que faltar volta como pendência.
 */
final class EditalCompleto
{
    public function __construct(private MontagemEdital $regra) {}

    /** Modelos de edital que servem a este processo, com o recomendado primeiro. */
    public function modelos(Processo $p): Collection
    {
        $lista = ModeloDocumento::where('tipo', 'minuta_edital')->where('ativo', true)->orderBy('titulo')->get()
            ->filter(fn (ModeloDocumento $m) => in_array($m->aplicacao, ['todos', 'licitacao'], true) && ($m->categoria === null || $m->categoria === $p->categoria))
            ->values();
        $codigo = $this->codigoRecomendado($p);

        return $lista->sortBy(fn (ModeloDocumento $m) => $m->codigo === $codigo ? 0 : 1)->values();
    }

    /** Valores iniciais do painel, puxados do processo, do TR, do ETP e da minuta do contrato. @return array<string, mixed> */
    public function dadosIniciais(Processo $p): array
    {
        $tr = $this->conteudo($p, 'termo_referencia');
        $etp = $this->conteudo($p, 'etp');
        $contrato = $this->conteudo($p, 'minuta_contrato');
        $hab = trim((string) ($tr['h_selecao'] ?? ''));

        return [
            'sessao_em' => $p->abertura_propostas_em?->format('Y-m-d\TH:i'),
            'local' => '',
            'prazo_assinatura' => (int) Parametros::valor('edital_prazo_assinatura_dias', $p->orgao_id),
            'garantia_pct' => MontagemEdital::garantiaNoContrato((string) ($contrato['texto'] ?? '')),
            'garantia_justificativa' => '',
            'habilitacao' => $hab,
            'regime' => MontagemEdital::regimeNoTexto(implode("\n", [(string) ($tr['c_solucao'] ?? ''), (string) ($etp['vii_solucao'] ?? '')])),
            'visita' => null,
            'visita_justificativa' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $entrada  o que o servidor confirmou no painel
     * @return array{texto: string, pendentes: list<string>, alertas: list<string>}
     */
    public function montar(Processo $p, ModeloDocumento $modelo, array $entrada): array
    {
        $p->loadMissing('orgao');
        $texto = (string) ($modelo->renderizar($p)['texto'] ?? '');
        $obra = $this->ehEngenharia($p);

        return $this->regra->montar($texto, [
            'sessao_em' => $entrada['sessao_em'] ?? null,
            'local' => $entrada['local'] ?? null,
            'modo_disputa' => $p->modo_disputa && $p->modo_disputa !== ModoDisputa::NaoSeAplica->value ? ModoDisputa::from($p->modo_disputa)->rotulo() : null,
            'prazo_assinatura' => isset($entrada['prazo_assinatura']) && $entrada['prazo_assinatura'] !== '' ? (int) $entrada['prazo_assinatura'] : null,
            'garantia_pct' => isset($entrada['garantia_pct']) && $entrada['garantia_pct'] !== '' && $entrada['garantia_pct'] !== null ? (float) str_replace(',', '.', (string) $entrada['garantia_pct']) : null,
            'garantia_justificativa' => $entrada['garantia_justificativa'] ?? null,
            'habilitacao' => $entrada['habilitacao'] ?? null,
            'regime' => $obra ? ($entrada['regime'] ?? null) : null,
            'visita' => $obra ? ($entrada['visita'] ?? null) : null,
            'visita_justificativa' => $entrada['visita_justificativa'] ?? null,
            'cidade' => $p->orgao?->municipio ? $p->orgao->municipio.($p->orgao->uf ? "/{$p->orgao->uf}" : '') : null,
            'orcamento_sigiloso' => (bool) $p->orcamento_sigiloso,
            'grande_vulto_engenharia' => $p->matrizDeRiscosObrigatoria(),
            'bens' => (int) $p->categoria === 2,
            'criterio' => (int) $p->criterio_julgamento,
            'itens' => $p->itens()->get()->map(fn ($i) => ['numero' => (int) $i->numero, 'descricao' => (string) $i->descricao, 'beneficio' => (int) $i->tipo_beneficio])->all(),
        ]);
    }

    public function ehEngenharia(Processo $p): bool
    {
        return in_array((int) $p->categoria, [7, 9], true);
    }

    /**
     * Edital e anexos para o caderno: a versão mais recente de cada peça, com o que ainda falta.
     *
     * @return array{edital: ?DocumentoProcesso, anexos: list<array{numero: string, titulo: string, tipo: ?string, doc: ?DocumentoProcesso}>, pendencias: list<string>}
     */
    public function caderno(Processo $p): array
    {
        $edital = $p->documentoAtual('minuta_edital');
        $anexos = [];
        $pendencias = [];
        foreach (['I' => 'termo_referencia', 'II' => 'minuta_contrato', 'III' => 'mapa_riscos', 'IV' => null] as $n => $tipo) {
            $doc = $tipo ? $p->documentoAtual($tipo) : null;
            $anexos[] = ['numero' => $n, 'titulo' => MontagemEdital::ANEXOS[$n], 'tipo' => $tipo, 'doc' => $doc];
            if ($tipo && ! $doc) {
                $pendencias[] = "Anexo {$n}, ".MontagemEdital::ANEXOS[$n].': peça ainda não elaborada.';
            } elseif ($doc && $doc->situacao !== 'concluido') {
                $pendencias[] = "Anexo {$n}, ".MontagemEdital::ANEXOS[$n].": versão {$doc->versao} ainda não concluída.";
            }
        }
        if (! $edital) {
            $pendencias[] = 'O edital ainda não foi salvo.';
        } else {
            if ($edital->situacao !== 'concluido') {
                $pendencias[] = "Edital: versão {$edital->versao} ainda não concluída.";
            }
            foreach (MontagemEdital::pendentes((string) ($edital->conteudo['texto'] ?? '')) as $trecho) {
                $pendencias[] = "Edital: completar [{$trecho}].";
            }
        }

        return ['edital' => $edital, 'anexos' => $anexos, 'pendencias' => $pendencias];
    }

    public function pdf(Processo $p): string
    {
        $p->loadMissing(['orgao', 'unidade']);
        $c = $this->caderno($p);

        return Pdf::daView('processos.caderno-edital-pdf', $c + ['p' => $p, 'riscos' => $p->riscos()->get()], "Edital · processo {$p->identificacao()}");
    }

    private function codigoRecomendado(Processo $p): string
    {
        $m = $p->modalidadeEnum();
        if ($this->ehEngenharia($p) && in_array($m, [Modalidade::ConcorrenciaEletronica, Modalidade::ConcorrenciaPresencial], true)) {
            return 'edital-concorrencia-obras';
        }

        return match ($m) {
            Modalidade::PregaoPresencial => 'edital-pregao-presencial',
            Modalidade::ConcorrenciaEletronica => 'edital-concorrencia-eletronica',
            Modalidade::ConcorrenciaPresencial => 'edital-concorrencia-presencial',
            default => 'edital-pregao-eletronico',
        };
    }

    /** @return array<string, mixed> */
    private function conteudo(Processo $p, string $tipo): array
    {
        $d = $p->documentoAtual($tipo);

        return $d && $d->origem !== 'arquivo' ? ($d->conteudo ?? []) : [];
    }
}
