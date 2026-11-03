<?php

namespace App\Licitacao\Contratos;

use App\Licitacao\Regras\Sancoes;
use App\Licitacao\RegraViolada;
use App\Models\Fornecedor;
use App\Models\Orgao;
use App\Models\ProcessoSancionador;
use App\Models\SancaoFornecedor;
use App\Models\User;
use App\Support\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Processo de responsabilização (arts. 155 a 163): instauração → intimação com prazo de defesa (arts. 157 e 158)
 * → defesa → provas e alegações finais, se requeridas (art. 158, § 2º) → parecer jurídico (obrigatório na
 * inidoneidade, art. 156, § 6º) → decisão com dosimetria (art. 156, § 1º) → recurso ou reconsideração
 * (arts. 166 e 167, com efeito suspensivo, art. 168) → sanção definitiva → CEIS/CNEP (art. 161) → reabilitação (art. 163).
 */
final class Responsabilizacao
{
    public function __construct(private Sancoes $regras) {}

    /**
     * @param  array{infracoes: list<string>, fatos: string, sancao_proposta: string, justificativa_agravamento?: ?string, comissao?: list<int>, comissao_estaveis?: bool, contrato_id?: ?int, processo_id?: ?int}  $d
     */
    public function instaurar(Fornecedor $f, array $d, User $u): ProcessoSancionador
    {
        $comissao = array_values(array_unique(array_map('intval', $d['comissao'] ?? [])));
        $motivos = $this->regras->validarSancao($d['infracoes'], $d['sancao_proposta'], $d['justificativa_agravamento'] ?? null);
        if (mb_strlen(trim($d['fatos'])) < 30) {
            $motivos[] = 'Descreva os fatos com data, contrato ou certame e o que foi descumprido (mínimo 30 caracteres).';
        }
        if ($this->regras->exigeComissao($d['sancao_proposta'])) {
            $validos = User::where('orgao_id', $f->orgao_id)->whereIn('id', $comissao)->count();
            if ($validos < 2) {
                $motivos[] = 'Impedimento e inidoneidade exigem comissão de 2 ou mais servidores (art. 158).';
            }
            if (empty($d['comissao_estaveis'])) {
                $motivos[] = 'Confirme que os membros da comissão são servidores estáveis ou empregados públicos com mais de 3 anos (art. 158).';
            }
        }
        if ($motivos) {
            throw new RegraViolada($motivos);
        }

        return DB::transaction(function () use ($f, $d, $u, $comissao) {
            $ano = now()->year;
            Orgao::whereKey($f->orgao_id)->lockForUpdate()->first(); // numeração sequencial sem corrida
            $seq = ProcessoSancionador::where('orgao_id', $f->orgao_id)->where('numero', 'like', "PS-{$ano}-%")->count() + 1;

            return ProcessoSancionador::create([
                'orgao_id' => $f->orgao_id, 'fornecedor_id' => $f->id, 'contrato_id' => $d['contrato_id'] ?? null, 'processo_id' => $d['processo_id'] ?? null,
                'numero' => sprintf('PS-%d-%03d', $ano, $seq), 'infracoes' => array_values($d['infracoes']), 'fatos' => trim($d['fatos']),
                'sancao_proposta' => $d['sancao_proposta'], 'justificativa_agravamento' => ($d['justificativa_agravamento'] ?? null) ?: null,
                'comissao' => $comissao ?: null, 'instaurado_por' => $u->id,
            ]);
        });
    }

    /** Intimação do fornecedor: abre o prazo de 15 dias úteis para defesa e gera a notificação em PDF. */
    public function intimar(ProcessoSancionador $ps, string $data, string $meio): void
    {
        $this->exigir($ps, ['instaurado']);
        $quando = CarbonImmutable::parse($data);
        $m = [];
        if ($quando->gt(today())) {
            $m[] = 'A data da intimação não pode ser futura.';
        }
        if (mb_strlen(trim($meio)) < 5) {
            $m[] = 'Informe o meio da intimação (e-mail com confirmação, carta com AR, publicação).';
        }
        if ($m) {
            throw new RegraViolada($m);
        }

        $ps->forceFill(['intimado_em' => $quando->toDateString(), 'intimacao_meio' => trim($meio), 'prazo_defesa' => $this->regras->prazoDefesa($quando)->toDateString(), 'situacao' => 'defesa'])->save();

        $ps->load(['orgao', 'fornecedor', 'contrato', 'processo']);
        $conteudo = Pdf::daView('sancoes.intimacao-pdf', ['ps' => $ps, 'infracoes' => Sancoes::INFRACOES]);
        $caminho = $this->pasta($ps).'/intimacao-'.$ps->numero.'.pdf';
        Storage::disk('local')->put($caminho, $conteudo);
        $ps->forceFill(['intimacao_caminho' => $caminho])->save();
    }

    public function registrarDefesa(ProcessoSancionador $ps, string $texto, bool $provas, string $data, ?UploadedFile $arquivo = null): void
    {
        $this->exigir($ps, ['defesa']);
        $quando = CarbonImmutable::parse($data);
        $m = [];
        if ($quando->toDateString() > $ps->prazo_defesa->toDateString()) {
            $m[] = 'Defesa fora do prazo, que terminou em '.$ps->prazo_defesa->format('d/m/Y').' (15 dias úteis, arts. 157 e 158).';
        }
        if ($quando->gt(today()) || $quando->lt($ps->intimado_em)) {
            $m[] = 'A data de recebimento da defesa deve ficar entre a intimação e hoje.';
        }
        if (mb_strlen(trim($texto)) < 20) {
            $m[] = 'Registre o resumo da defesa (mínimo 20 caracteres) e anexe o documento.';
        }
        if ($m) {
            throw new RegraViolada($m);
        }

        $dados = ['defesa' => trim($texto), 'defesa_em' => $quando->toDateString(), 'provas_requeridas' => $provas, 'situacao' => $provas ? 'instrucao' : 'decisao'];
        if ($arquivo) {
            $dados['defesa_caminho'] = $arquivo->storeAs($this->pasta($ps), 'defesa-'.Str::uuid().'.'.($arquivo->guessExtension() ?: 'pdf'), 'local');
            $dados['defesa_hash'] = hash_file('sha256', $arquivo->getRealPath());
        }
        $ps->forceFill($dados)->save();
    }

    /** Fim da produção de provas: intima para alegações finais em 15 dias úteis (art. 158, § 2º). */
    public function encerrarInstrucao(ProcessoSancionador $ps, string $relatorio, string $intimacao): void
    {
        $this->exigir($ps, ['instrucao']);
        if (mb_strlen(trim($relatorio)) < 30) {
            throw new RegraViolada(['Resuma as provas produzidas (mínimo 30 caracteres).']);
        }
        $ps->forceFill(['instrucao' => trim($relatorio), 'prazo_alegacoes' => $this->regras->prazoAlegacoes(CarbonImmutable::parse($intimacao))->toDateString(), 'situacao' => 'alegacoes'])->save();
    }

    public function registrarAlegacoes(ProcessoSancionador $ps, string $texto, string $data): void
    {
        $this->exigir($ps, ['alegacoes']);
        if ($data > $ps->prazo_alegacoes->toDateString()) {
            throw new RegraViolada(['Alegações fora do prazo, que terminou em '.$ps->prazo_alegacoes->format('d/m/Y').' (art. 158, § 2º).']);
        }
        if (mb_strlen(trim($texto)) < 20) {
            throw new RegraViolada(['Registre o resumo das alegações finais (mínimo 20 caracteres).']);
        }
        $ps->forceFill(['alegacoes' => trim($texto), 'alegacoes_em' => $data, 'situacao' => 'decisao'])->save();
    }

    public function registrarParecer(ProcessoSancionador $ps, string $texto, User $u): void
    {
        $this->exigir($ps, ['defesa', 'instrucao', 'alegacoes', 'decisao']);
        if (mb_strlen(trim($texto)) < 30) {
            throw new RegraViolada(['O parecer precisa de conclusão fundamentada (mínimo 30 caracteres).']);
        }
        $ps->forceFill(['parecer_juridico' => trim($texto), 'parecer_por' => $u->id, 'parecer_em' => now()])->save();
    }

    /**
     * Decisão da autoridade, com dosimetria (art. 156, § 1º). A multa pode ser a sanção ou somar-se a outra (§ 7º).
     *
     * @param  array{resultado: string, sancao_tipo?: ?string, base_calculo?: float|string|null, multa_valor?: float|string|null, duracao_meses?: int|string|null, dosimetria?: ?string, decisao: string}  $d
     */
    public function decidir(ProcessoSancionador $ps, array $d, User $u): void
    {
        $prazoVencido = match ($ps->situacao) {
            'defesa' => $ps->prazo_defesa->lt(today()),
            'alegacoes' => $ps->prazo_alegacoes->lt(today()),
            'decisao' => true,
            default => false,
        };
        if (! $prazoVencido) {
            throw new RegraViolada(['A decisão só vem depois da defesa, ou do fim do prazo dela, e das alegações finais, se houve provas (arts. 157 e 158).']);
        }
        if (mb_strlen(trim($d['decisao'])) < 30) {
            throw new RegraViolada(['Fundamente a decisão (mínimo 30 caracteres).']);
        }

        if ($d['resultado'] === 'arquivado') {
            $ps->forceFill(['resultado' => 'arquivado', 'decisao' => trim($d['decisao']), 'decidido_por' => $u->id, 'decidido_em' => today(), 'situacao' => 'arquivado'])->save();

            return;
        }

        $tipo = (string) ($d['sancao_tipo'] ?? '');
        $valores = $this->validarAplicacao($ps, $tipo, $d);

        $ps->forceFill($valores + [
            'resultado' => 'aplicada', 'sancao_tipo' => $tipo, 'dosimetria' => trim((string) $d['dosimetria']), 'decisao' => trim($d['decisao']),
            'decidido_por' => $u->id, 'decidido_em' => today(), 'prazo_recurso' => $this->regras->prazoRecurso(today())->toDateString(), 'situacao' => 'decidido',
        ])->save();
    }

    /** Recurso (art. 166) ou pedido de reconsideração, na inidoneidade (art. 167). Tem efeito suspensivo (art. 168). */
    public function recorrer(ProcessoSancionador $ps, string $texto, string $data): void
    {
        $this->exigir($ps, ['decidido']);
        if ($ps->recurso_em) {
            throw new RegraViolada(['Já há recurso registrado.']);
        }
        if ($data > $ps->prazo_recurso->toDateString()) {
            throw new RegraViolada(['Recurso fora do prazo, que terminou em '.$ps->prazo_recurso->format('d/m/Y').' (15 dias úteis, arts. 166 e 167).']);
        }
        if (mb_strlen(trim($texto)) < 20) {
            throw new RegraViolada(['Registre o resumo das razões (mínimo 20 caracteres).']);
        }

        $meio = $this->regras->meioDeImpugnacao($ps->sancao_tipo);
        $recebido = CarbonImmutable::parse($data);
        $ps->forceFill([
            'recurso_meio' => $meio, 'recurso_texto' => trim($texto), 'recurso_em' => $data, 'situacao' => 'recurso',
            'prazo_reconsideracao' => $meio === 'recurso' ? $this->regras->prazoReconsideracao($recebido)->toDateString() : null,
            'prazo_decisao_recurso' => $meio === 'reconsideracao' ? $this->regras->prazoDecisaoRecurso($recebido)->toDateString() : null,
        ])->save();
    }

    /** No recurso, quem decidiu pode reconsiderar em 5 dias úteis; se mantiver, sobe à autoridade superior (art. 166, parágrafo único). */
    public function reconsiderar(ProcessoSancionador $ps, bool $reconsidera, string $texto, User $u): void
    {
        $this->exigir($ps, ['recurso']);
        if ($ps->recurso_meio !== 'recurso' || $ps->reconsiderado_por) {
            throw new RegraViolada(['Não há recurso aguardando reconsideração.']);
        }
        if (mb_strlen(trim($texto)) < 30) {
            throw new RegraViolada(['Fundamente (mínimo 30 caracteres).']);
        }

        if ($reconsidera) {
            $ps->forceFill(['reconsideracao_texto' => trim($texto), 'reconsiderado_por' => $u->id, 'recurso_resultado' => 'provido', 'recurso_decisao' => 'Reconsiderada pela autoridade que decidiu.', 'recurso_decidido_em' => today(), 'situacao' => 'arquivado'])->save();

            return;
        }
        $ps->forceFill(['reconsideracao_texto' => trim($texto), 'reconsiderado_por' => $u->id, 'prazo_decisao_recurso' => $this->regras->prazoDecisaoRecurso(today())->toDateString()])->save();
    }

    /**
     * Decisão do recurso pela autoridade superior (art. 166) ou da reconsideração (art. 167).
     *
     * @param  array{resultado: string, decisao: string, sancao_tipo?: ?string, base_calculo?: mixed, multa_valor?: mixed, duracao_meses?: mixed, dosimetria?: ?string}  $d
     */
    public function decidirRecurso(ProcessoSancionador $ps, array $d, User $u): void
    {
        $this->exigir($ps, ['recurso']);
        if ($ps->recurso_meio === 'recurso' && ! $ps->reconsiderado_por) {
            throw new RegraViolada(['Antes, quem decidiu precisa reconsiderar ou manter a decisão (art. 166, parágrafo único).']);
        }
        if ($ps->recurso_meio === 'recurso' && in_array($u->id, [$ps->reconsiderado_por, $ps->decidido_por], true)) {
            throw new RegraViolada(['O recurso é decidido pela autoridade superior, não por quem aplicou ou manteve a sanção (art. 166, parágrafo único).']);
        }
        if (! array_key_exists($d['resultado'], ProcessoSancionador::RESULTADOS_RECURSO) || mb_strlen(trim($d['decisao'])) < 30) {
            throw new RegraViolada(['Informe o resultado e fundamente a decisão (mínimo 30 caracteres).']);
        }

        $dados = ['recurso_resultado' => $d['resultado'], 'recurso_decisao' => trim($d['decisao']), 'recurso_decidido_por' => $u->id, 'recurso_decidido_em' => today()];
        if ($d['resultado'] === 'provido') {
            $dados['situacao'] = 'arquivado';
        } else {
            $dados['situacao'] = 'decidido';
            if ($d['resultado'] === 'parcial') {
                $tipo = (string) ($d['sancao_tipo'] ?? $ps->sancao_tipo);
                $dados += $this->validarAplicacao($ps, $tipo, $d) + ['sancao_tipo' => $tipo, 'dosimetria' => trim((string) $d['dosimetria'])];
            }
        }
        $ps->forceFill($dados)->save();
    }

    /** Sem recurso no prazo, ou com recurso decidido, a sanção passa a produzir efeitos (art. 168) e vai ao cadastro do fornecedor. */
    public function tornarDefinitiva(ProcessoSancionador $ps): SancaoFornecedor
    {
        $this->exigir($ps, ['decidido']);
        if (! $ps->recurso_decidido_em && $ps->prazo_recurso->gte(today())) {
            throw new RegraViolada(['O prazo de recurso vai até '.$ps->prazo_recurso->format('d/m/Y').'. A sanção só produz efeitos depois (art. 168).']);
        }

        return DB::transaction(function () use ($ps) {
            $inicio = CarbonImmutable::today();
            $tipo = $ps->sancao_tipo;
            $sancao = SancaoFornecedor::create([
                'orgao_id' => $ps->orgao_id, 'fornecedor_id' => $ps->fornecedor_id, 'processo_sancionador_id' => $ps->id, 'tipo' => $tipo,
                'inicio' => $inicio->toDateString(), 'fim' => $ps->duracao_meses ? $this->regras->fimDosEfeitos($inicio, $ps->duracao_meses)->toDateString() : null,
                'processo' => $ps->numero, 'multa_valor' => $ps->multa_valor,
                'abrangencia' => match ($tipo) {
                    'impedimento' => 'ente', 'inidoneidade' => 'nacional', default => null
                },
                'fundamento' => 'Art. 155, '.implode(', ', $ps->infracoes).', e art. 156 da Lei nº 14.133/2021. '.$ps->decisao,
            ]);
            $ps->forceFill(['situacao' => 'definitivo', 'definitivo_em' => $inicio->toDateString(), 'prazo_ceis' => $this->regras->prazoCeis($inicio)->toDateString()])->save();

            return $sancao;
        });
    }

    public function registrarCeis(ProcessoSancionador $ps, string $data, string $protocolo): void
    {
        $this->exigir($ps, ['definitivo', 'reabilitado']);
        if (mb_strlen(trim($protocolo)) < 3 || $data > today()->toDateString()) {
            throw new RegraViolada(['Informe a data e o número ou o comprovante do registro no cadastro nacional.']);
        }
        $ps->forceFill(['ceis_informado_em' => $data, 'ceis_protocolo' => trim($protocolo)])->save();
    }

    /**
     * Reabilitação (art. 163): encerra os efeitos do impedimento ou da inidoneidade.
     *
     * @param  array{reparacao: bool, multa_paga: bool, condicoes: bool, integridade: bool, parecer: bool}  $requisitos
     */
    public function reabilitar(ProcessoSancionador $ps, array $requisitos, string $fundamento): void
    {
        $this->exigir($ps, ['definitivo']);
        $m = $this->regras->validarReabilitacao((string) $ps->sancao_tipo, $ps->definitivo_em, CarbonImmutable::today(), $ps->infracoes, $requisitos);
        if (mb_strlen(trim($fundamento)) < 30) {
            $m[] = 'Fundamente a reabilitação (mínimo 30 caracteres).';
        }
        if ($m) {
            throw new RegraViolada($m);
        }

        DB::transaction(function () use ($ps, $fundamento) {
            $ps->sancao?->update(['fim' => today()->subDay()->toDateString()]);
            $ps->forceFill(['situacao' => 'reabilitado', 'reabilitado_em' => today(), 'reabilitacao_fundamento' => trim($fundamento)])->save();
        });
    }

    /**
     * @return array{base_calculo: ?float, multa_valor: ?float, duracao_meses: ?int}
     */
    private function validarAplicacao(ProcessoSancionador $ps, string $tipo, array $d): array
    {
        $multa = isset($d['multa_valor']) && $d['multa_valor'] !== '' ? (float) $d['multa_valor'] : null;
        $base = isset($d['base_calculo']) && $d['base_calculo'] !== '' ? (float) $d['base_calculo'] : null;
        $meses = isset($d['duracao_meses']) && $d['duracao_meses'] !== '' ? (int) $d['duracao_meses'] : null;

        $m = $this->regras->validarSancao($ps->infracoes, $tipo, $ps->justificativa_agravamento);
        $m = array_merge($m, $this->regras->validarDuracao($tipo, $meses));
        if ($tipo === 'multa' && $multa === null) {
            $m[] = 'Informe o valor da multa.';
        }
        if ($multa !== null) {
            $m = array_merge($m, $this->regras->validarMulta($multa, (float) $base));
        }
        if ($tipo === 'inidoneidade' && ! $ps->parecer_juridico) {
            $m[] = 'A declaração de inidoneidade exige análise jurídica prévia (art. 156, § 6º).';
        }
        if ($this->regras->exigeComissao($tipo) && count($ps->comissao ?? []) < 2) {
            $m[] = 'Impedimento e inidoneidade exigem processo conduzido por comissão (art. 158). Instaure de novo com a comissão.';
        }
        if (mb_strlen(trim((string) ($d['dosimetria'] ?? ''))) < 30) {
            $m[] = 'Registre a dosimetria: natureza e gravidade, peculiaridades, agravantes e atenuantes, danos e programa de integridade (art. 156, § 1º).';
        }
        if ($m) {
            throw new RegraViolada($m);
        }

        return ['base_calculo' => $base, 'multa_valor' => $multa, 'duracao_meses' => in_array($tipo, ['impedimento', 'inidoneidade'], true) ? $meses : null];
    }

    /** @param list<string> $situacoes */
    private function exigir(ProcessoSancionador $ps, array $situacoes): void
    {
        if (! in_array($ps->situacao, $situacoes, true)) {
            throw new RegraViolada(['Ação indisponível na situação atual: '.(ProcessoSancionador::SITUACOES[$ps->situacao] ?? $ps->situacao).'.']);
        }
    }

    private function pasta(ProcessoSancionador $ps): string
    {
        return "orgaos/{$ps->orgao_id}/sancoes/{$ps->id}";
    }
}
