<?php

namespace App\Integracoes\Painel;

use App\Models\Ata;
use App\Models\Contrato;
use App\Models\Empenho;
use App\Models\Fornecedor;
use App\Models\ItemPca;
use App\Models\ItemProcesso;
use App\Models\Liquidacao;
use App\Models\Orgao;
use App\Models\Processo;
use App\Models\TermoContrato;
use App\Models\Unidade;
use App\Support\Documento;
use Illuminate\Database\Eloquent\Model;

/**
 * Traduz os registros do NexPlanGov para os tipos e campos da API de entrada do Painel de Compras
 * (SIC/SELC, /api/integracao/v1). Cada registro vem junto com aquilo de que depende no painel
 * (unidade, fornecedor, processo, item de catálogo), na ordem de envio, porque o painel valida as
 * referências: o contrato só entra se o processo e a unidade já existirem lá.
 *
 * Os códigos de modalidade e amparo são os do PNCP (Lei 14.133, art. 174) e viram os códigos do painel.
 */
final class MapeadorPainel
{
    /** Ordem de envio exigida pelo painel: cada tipo só referencia os anteriores. */
    public const ORDEM = ['UNIDADES', 'FORNECEDORES', 'CATALOGO', 'PCA', 'PROCESSOS', 'ATAS', 'CONTRATOS', 'ADITIVOS', 'EXECUCAO_FINANCEIRA'];

    /** Modalidade do PNCP (tabela 5.2) → [sistemática, forma de aquisição, modalidade legal] do painel. */
    private const MODALIDADES = [
        2 => ['DIALOGO', 'DIALOGO', 'DIALOGO_14133'], 3 => ['CONCURSO', 'CONCURSO', 'CONCURSO_14133'],
        4 => ['CONCORRENCIA', 'CONCORRENCIA_ELETRONICA', 'CONCORRENCIA_14133'], 5 => ['CONCORRENCIA', 'CONCORRENCIA_PRESENCIAL', 'CONCORRENCIA_14133'],
        6 => ['PREGAO', 'PREGAO_ELETRONICO', 'PREGAO_14133'], 7 => ['PREGAO', 'PREGAO_PRESENCIAL', 'PREGAO_14133'],
        8 => ['DISPENSA', null, null], 9 => ['INEXIGIBILIDADE', 'INEXIGIBILIDADE', null], 12 => ['CREDENCIAMENTO', 'CREDENCIAMENTO', null],
    ];

    /** Amparo legal do PNCP → código do painel (só os que o painel cadastra; os demais vão sem amparo). */
    private const AMPAROS = [
        1 => 'L14133_ART28_I', 2 => 'L14133_ART28_II', 6 => 'L14133_ART74', 7 => 'L14133_ART74', 8 => 'L14133_ART74', 9 => 'L14133_ART74',
        10 => 'L14133_ART74', 16 => 'L14133_ART79', 17 => 'L14133_ART74', 18 => 'L14133_ART75_I', 19 => 'L14133_ART75_II',
    ];

    private const SITUACAO_PROCESSO = [
        'planejamento' => 'Em andamento', 'analise_juridica' => 'Em andamento', 'aguardando_autorizacao' => 'Em andamento', 'publicado' => 'Em andamento',
        'em_julgamento' => 'Em andamento', 'homologado' => 'Homologado', 'contratado' => 'Contratado', 'deserto' => 'Deserto',
        'fracassado' => 'Fracassado', 'revogado' => 'Revogado', 'anulado' => 'Anulado',
    ];

    private const SITUACAO_ITEM = ['pendente' => 'Em andamento', 'homologado' => 'Homologado', 'cancelado' => 'Cancelado', 'deserto' => 'Deserto', 'fracassado' => 'Fracassado'];

    /** Tipo de benefício ME/EPP do PNCP (tabela 5.7) → painel. */
    private const BENEFICIO = [1 => 'Exclusivo', 3 => 'Cota reservada'];

    private const SITUACAO_PLANO = ['rascunho' => 'ELABORACAO', 'em_aprovacao' => 'ELABORACAO', 'aprovado' => 'APROVADO'];

    /** @var array<string, array<string, array<string, mixed>>> tipo → chave → registro */
    private array $lotes = [];

    /**
     * Registros de um modelo e de tudo de que ele depende, agrupados por tipo na ordem de envio.
     *
     * @return array<string, list<array<string, mixed>>> tipo → registros
     */
    public static function para(Model $m): array
    {
        $map = new self;
        $map->adicionar($m);

        return $map->lotes();
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function lotes(): array
    {
        $saida = [];
        foreach (self::ORDEM as $tipo) {
            if (! empty($this->lotes[$tipo])) {
                $saida[$tipo] = array_values($this->lotes[$tipo]);
            }
        }

        return $saida;
    }

    public function adicionar(Model $m): void
    {
        match (true) {
            $m instanceof Orgao => $this->unidade($m, null),
            $m instanceof Unidade => $this->unidade($m->orgao, $m),
            $m instanceof Fornecedor => $this->fornecedor($m),
            $m instanceof ItemPca => $this->itemPca($m),
            $m instanceof Processo => $this->processo($m),
            $m instanceof ItemProcesso => $this->processo($m->processo),
            $m instanceof Ata => $this->ata($m),
            $m instanceof Contrato => $this->contrato($m),
            $m instanceof TermoContrato => $this->termo($m),
            $m instanceof Empenho => $this->empenho($m),
            $m instanceof Liquidacao => $this->liquidacao($m),
            default => null,
        };
    }

    private function guardar(string $tipo, string $chave, array $registro): void
    {
        $this->lotes[$tipo][$chave] = array_filter($registro, fn ($v) => $v !== null && $v !== '');
    }

    /** Código da unidade compradora no painel: SIGLA-DO-ÓRGÃO-SIGLA-DA-UNIDADE, ou só a sigla do órgão (sede). */
    public static function codigoUnidade(Orgao $o, ?Unidade $u): string
    {
        return mb_strtoupper($u ? "{$o->sigla}-{$u->sigla}" : $o->sigla);
    }

    private function unidade(Orgao $o, ?Unidade $u): string
    {
        $codigo = self::codigoUnidade($o, $u);
        // O município fica de fora de propósito: o painel recusa o registro quando o nome não bate com o IBGE.
        $this->guardar('UNIDADES', $codigo, [
            'orgao_sigla' => $o->sigla, 'orgao_nome' => $o->nome, 'orgao_cnpj' => $o->cnpj ? Documento::digitos($o->cnpj) : null,
            'unidade_codigo' => $codigo, 'unidade_nome' => $u ? "{$o->sigla} - {$u->nome}" : $o->nome,
        ]);

        return $codigo;
    }

    private function fornecedor(?Fornecedor $f): ?string
    {
        if (! $f) {
            return null;
        }
        $doc = $f->documentoCompleto();
        // CPF de pessoa física vai cifrado no NexPlanGov e aberto só aqui: o painel o guarda mascarado (LGPD, art. 7º, III).
        $this->guardar('FORNECEDORES', $doc, [
            'documento' => $doc, 'razao_social' => $f->razao_social, 'nome_fantasia' => $f->nome_fantasia,
            'porte' => in_array($f->porte, ['ME', 'EPP'], true) ? $f->porte : null, 'uf' => $f->uf, 'email' => $f->email, 'telefone' => $f->telefone,
        ]);

        return $doc;
    }

    private static function numero(Model $m): string
    {
        return "{$m->numero}/{$m->ano}";
    }

    private function itemPca(ItemPca $i): void
    {
        $plano = $i->plano;
        $unidade = $i->unidade ? $this->unidade($plano->orgao, $i->unidade) : null;
        $this->unidade($plano->orgao, null);
        $categoria = match (true) {
            (int) $i->categoria === 7 => 'Obra', (int) $i->categoria === 3 => 'TIC', $i->material_servico === 'S' => 'Serviço', default => 'Material',
        };
        $this->guardar('PCA', "{$plano->ano}|{$i->numero}", [
            'exercicio' => $plano->ano, 'orgao' => $plano->orgao->sigla, 'numero_demanda' => (string) $i->numero, 'objeto' => $i->descricao,
            'valor_estimado' => (float) $i->valor_total_estimado, 'unidade_requisitante' => $unidade, 'categoria' => $categoria,
            'quantidade_estimada' => (float) $i->quantidade, 'data_pretendida' => $i->data_desejada?->toDateString(),
            'prioridade' => ItemPca::PRIORIDADES[$i->prioridade] ?? null, 'situacao_pca' => self::SITUACAO_PLANO[$plano->situacao] ?? null,
            'data_aprovacao' => $plano->aprovado_em?->toDateString(),
        ]);
    }

    private function processo(?Processo $p): ?string
    {
        if (! $p) {
            return null;
        }
        $numero = self::numero($p);
        [$sistematica, $forma, $modalidade] = self::MODALIDADES[$p->modalidade] ?? ['OUTRA', null, null];
        $base = [
            'numero_publicacao' => $numero, 'identificador' => $p->numero_controle_pncp ?: "NEXPLANGOV-PROCESSO-{$p->id}",
            'numero_processo_sei' => $p->numero_sei, 'numero_licitacao' => $p->numero_compra,
            'unidade' => $this->unidade($p->orgao, $p->unidade), 'objeto' => $p->objeto,
            'data_abertura' => ($p->abertura_propostas_em ?? $p->created_at)?->toDateString(),
            'sistematica' => $sistematica, 'forma' => $forma, 'modalidade' => $modalidade, 'amparo' => self::AMPAROS[$p->amparo_legal] ?? null,
            'regime' => 'L14133', 'registro_precos' => (bool) $p->srp, 'eletronico' => in_array($p->modalidade, [4, 6], true) || (bool) $p->com_disputa,
            'situacao' => self::SITUACAO_PROCESSO[$p->situacao] ?? null,
        ];
        $itens = $p->itens()->with('fornecedorVencedor')->orderBy('numero')->get();
        if ($itens->isEmpty()) {
            $this->guardar('PROCESSOS', $numero, $base + ['valor_estimado' => (float) $p->valor_estimado, 'valor_homologado' => $p->valor_homologado !== null ? (float) $p->valor_homologado : null]);

            return $numero;
        }
        foreach ($itens as $i) {
            $this->guardar('PROCESSOS', "$numero|{$i->numero}", $base + $this->itemProcesso($i));
        }

        return $numero;
    }

    private function itemProcesso(ItemProcesso $i): array
    {
        $r = [
            'numero_item' => $i->numero, 'item_codigo' => $i->codigo_catalogo, 'descricao_item' => $i->descricao, 'unidade_medida' => $i->unidade_medida,
            'quantidade_estimada' => (float) $i->quantidade, 'valor_unitario_estimado' => (float) $i->valor_unitario_estimado,
            'valor_total_estimado' => (float) $i->valor_total_estimado, 'situacao_item' => self::SITUACAO_ITEM[$i->situacao] ?? null,
            'beneficio_mpe' => self::BENEFICIO[$i->tipo_beneficio] ?? 'Nenhum',
        ];
        if ($i->fornecedorVencedor && $i->valor_unitario_homologado !== null) {
            $total = round((float) $i->quantidade * (float) $i->valor_unitario_homologado, 2);
            $r += [
                'fornecedor_documento' => $this->fornecedor($i->fornecedorVencedor), 'fornecedor_nome' => $i->fornecedorVencedor->razao_social,
                'quantidade_adjudicada' => (float) $i->quantidade, 'valor_unitario_adjudicado' => (float) $i->valor_unitario_homologado,
                'valor_total_adjudicado' => $total, 'valor_total_homologado' => $total,
            ];
        }

        return $r;
    }

    /** O painel só registra ata de item que está no catálogo dele: o item vai junto. */
    private function itemCatalogo(ItemProcesso $i): string
    {
        $codigo = $i->codigo_catalogo ?: "NEXPLANGOV-ITEM-{$i->id}";
        $this->guardar('CATALOGO', $codigo, [
            'codigo' => $codigo, 'descricao' => $i->descricao, 'tipo' => $i->material_servico === 'S' ? 'SERVICO' : 'MATERIAL', 'unidade_medida' => $i->unidade_medida,
        ]);

        return $codigo;
    }

    private function ata(Ata $a): void
    {
        $processo = $this->processo($a->processo);
        $fornecedor = $this->fornecedor($a->fornecedor);
        $this->unidade($a->orgao, null);
        foreach ($a->itens()->with('item')->get() as $ai) {
            $this->guardar('ATAS', self::numero($a)."|{$ai->id}", [
                'numero' => self::numero($a), 'identificador' => $a->numero_controle_pncp, 'orgao_gerenciador' => $a->orgao->sigla,
                'numero_publicacao' => $processo, 'objeto' => $a->processo?->objeto, 'data_assinatura' => $a->assinatura?->toDateString(),
                'vigencia_inicio' => $a->vigencia_inicio?->toDateString(), 'vigencia_fim' => $a->vigencia_fim?->toDateString(),
                'situacao' => in_array($a->situacao, ['vigente', 'expirada', 'cancelada', 'suspensa'], true) ? ucfirst($a->situacao) : null,
                'item_codigo' => $this->itemCatalogo($ai->item), 'fornecedor_documento' => $fornecedor, 'fornecedor_nome' => $a->fornecedor?->razao_social,
                'quantidade_registrada' => (float) $ai->quantidade_registrada, 'valor_unitario_estimado' => (float) $ai->item->valor_unitario_estimado,
                'valor_unitario_registrado' => (float) $ai->valor_unitario,
            ]);
        }
    }

    private function contrato(Contrato $c): array
    {
        $processo = $this->processo($c->processo);
        $unidade = $this->unidade($c->orgao, $c->processo?->unidade);
        $numero = self::numero($c);
        $this->guardar('CONTRATOS', $numero, [
            'numero' => $numero, 'identificador' => $c->numero_controle_pncp ?: "NEXPLANGOV-CONTRATO-{$c->id}", 'unidade' => $unidade,
            'fornecedor_documento' => $this->fornecedor($c->fornecedor), 'fornecedor_nome' => $c->fornecedor?->razao_social,
            'numero_publicacao' => $processo, 'objeto' => $c->objeto, 'valor_inicial' => (float) $c->valor_inicial, 'valor_atualizado' => (float) $c->valor_global,
            'data_assinatura' => $c->assinatura?->toDateString(), 'vigencia_inicio' => $c->vigencia_inicio?->toDateString(), 'vigencia_fim' => $c->vigencia_fim?->toDateString(),
            'situacao' => in_array($c->situacao, ['vigente', 'encerrado', 'rescindido', 'suspenso'], true) ? ucfirst($c->situacao) : null,
            'gestor' => $c->gestor?->name, 'fiscal' => $c->fiscal?->name,
        ]);

        return [$numero, $unidade, $processo];
    }

    private function termo(TermoContrato $t): void
    {
        [$contrato, $unidade] = $this->contrato($t->contrato);
        $valor = (float) $t->valor_acrescimo - (float) $t->valor_supressao + (float) $t->valor_reajuste;
        $prazo = $t->nova_vigencia_fim !== null;
        $tipo = match (true) {
            $t->tipo === 'apostilamento' => 'Apostilamento',
            $t->tipo === 'rescisao' => null, // rescisão muda a situação do contrato, enviada acima; não é aditivo
            $prazo && $valor != 0.0 => 'Misto',
            $prazo => 'Prazo',
            default => 'Valor',
        };
        if ($tipo === null) {
            return;
        }
        $this->guardar('ADITIVOS', "$contrato|{$t->numero}", [
            'contrato' => $contrato, 'unidade' => $unidade, 'numero' => $t->numero, 'tipo' => $tipo, 'valor' => $valor != 0.0 ? $valor : null,
            'nova_vigencia_fim' => $t->nova_vigencia_fim?->toDateString(), 'data_assinatura' => $t->assinatura?->toDateString(), 'justificativa' => $t->justificativa,
        ]);
    }

    /** Vínculo de um documento financeiro: contrato, ou o processo da ata. */
    private function vinculo(?Contrato $c, ?Ata $a, Orgao $o): array
    {
        if ($c) {
            [$contrato, $unidade] = $this->contrato($c);

            return ['contrato' => $contrato, 'unidade' => $unidade];
        }
        if ($a) {
            $this->ata($a);

            return ['processo' => self::numero($a->processo), 'unidade' => $this->unidade($o, $a->processo->unidade)];
        }

        return [];
    }

    private function empenho(Empenho $e): void
    {
        $v = $this->vinculo($e->contrato, $e->ata, $e->orgao);
        if ($v === []) {
            return; // o painel só aceita documento financeiro ligado a contrato ou processo
        }
        $this->guardar('EXECUCAO_FINANCEIRA', "E|{$e->exercicio}|{$e->numero}", $v + [
            'tipo' => 'Empenho', 'documento' => "{$e->exercicio}NE{$e->numero}", 'data' => $e->emitido_em?->toDateString(),
            'valor' => round((float) $e->valor - (float) $e->valor_anulado, 2),
        ]);
    }

    private function liquidacao(Liquidacao $l): void
    {
        if ($l->situacao === 'cancelado') {
            return;
        }
        $v = $this->vinculo($l->contrato, $l->empenho?->ata, $l->orgao);
        if ($v === []) {
            return;
        }
        $this->guardar('EXECUCAO_FINANCEIRA', "L|{$l->id}", $v + [
            'tipo' => 'Liquidação', 'documento' => "NL-{$l->id} (NF {$l->nota_fiscal})", 'data' => $l->liquidado_em?->toDateString(), 'valor' => (float) $l->valor_liquido,
        ]);
        if ($l->pago_em) {
            $this->guardar('EXECUCAO_FINANCEIRA', "P|{$l->id}", $v + [
                'tipo' => 'Pagamento', 'documento' => $l->ordem_bancaria ?: "OB-{$l->id}", 'data' => $l->pago_em->toDateString(), 'valor' => (float) $l->valor_liquido,
            ]);
        }
    }
}
