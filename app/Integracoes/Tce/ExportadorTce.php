<?php

namespace App\Integracoes\Tce;

use App\Models\Ata;
use App\Models\Contrato;
use App\Models\ItemProcesso;
use App\Models\Orgao;
use App\Models\Processo;
use App\Models\TermoContrato;
use Illuminate\Support\Carbon;
use RuntimeException;
use ZipArchive;

/**
 * Remessa de dados ao tribunal de contas (controle externo, art. 169, III). Layout genérico NexPlanGov:
 * CSV com separador ";" em UTF-8 e um manifesto com o SHA-256 de cada arquivo. Cada tribunal tem o seu
 * leiaute; este pacote é a base para o conversor do tribunal do órgão (ver docs/integracoes.md).
 * CPF de fornecedor pessoa física sai mascarado, como guardado na tela (LGPD).
 */
final class ExportadorTce
{
    public const VERSAO_LAYOUT = 'nexplangov-generico-1.0';

    /** @return string Caminho do ZIP gerado. */
    public function gerar(Orgao $orgao, Carbon $inicio, Carbon $fim, string $destino): string
    {
        if ($fim->lt($inicio)) {
            throw new RuntimeException('O fim do período vem antes do início.');
        }
        $inicio = $inicio->copy()->startOfDay();
        $fim = $fim->copy()->endOfDay();

        $arquivos = [
            'processos.csv' => $this->processos($orgao, $inicio, $fim),
            'itens.csv' => $this->itens($orgao, $inicio, $fim),
            'contratos.csv' => $this->contratos($orgao, $inicio, $fim),
            'termos_contrato.csv' => $this->termos($orgao, $inicio, $fim),
            'atas.csv' => $this->atas($orgao, $inicio, $fim),
        ];

        $manifesto = [
            'layout' => self::VERSAO_LAYOUT, 'orgao' => ['cnpj' => $orgao->cnpj, 'nome' => $orgao->nome, 'uf' => $orgao->uf, 'municipio' => $orgao->municipio],
            'periodo' => ['inicio' => $inicio->toDateString(), 'fim' => $fim->toDateString()], 'gerado_em' => now()->toIso8601String(),
            'base_legal' => 'Lei nº 14.133/2021, art. 169, III (controle externo)', 'arquivos' => [],
        ];

        $zip = new ZipArchive;
        if ($zip->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar o arquivo da remessa.');
        }
        foreach ($arquivos as $nome => [$cabecalho, $linhas]) {
            $csv = $this->csv($cabecalho, $linhas);
            $zip->addFromString($nome, $csv);
            $manifesto['arquivos'][$nome] = ['registros' => count($linhas), 'sha256' => hash('sha256', $csv)];
        }
        $zip->addFromString('manifesto.json', json_encode($manifesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return $destino;
    }

    private function processos(Orgao $o, Carbon $i, Carbon $f): array
    {
        $linhas = Processo::withoutGlobalScope('orgao')->where('orgao_id', $o->id)->whereBetween('created_at', [$i, $f])->orderBy('ano')->orderBy('numero')->get()
            ->map(fn ($p) => [$p->id, $p->identificacao(), $p->objeto, $p->modalidade, $p->modalidadeEnum()->rotulo(), $p->amparo_legal, $p->criterio_julgamento, $p->srp ? 'S' : 'N',
                $p->situacao, $this->valor($p->valor_estimado), $this->valor($p->valor_homologado), $p->created_at->toDateString(), $p->numero_controle_pncp])->all();

        return [['id', 'numero_ano', 'objeto', 'modalidade_codigo_pncp', 'modalidade', 'amparo_legal_codigo_pncp', 'criterio_julgamento_codigo_pncp', 'srp', 'situacao', 'valor_estimado', 'valor_homologado', 'data_abertura', 'numero_controle_pncp'], $linhas];
    }

    private function itens(Orgao $o, Carbon $i, Carbon $f): array
    {
        $linhas = ItemProcesso::withoutGlobalScope('orgao')->with(['fornecedorVencedor' => fn ($q) => $q->withoutGlobalScope('orgao')])->where('orgao_id', $o->id)
            ->whereHas('processo', fn ($q) => $q->withoutGlobalScope('orgao')->whereBetween('created_at', [$i, $f]))->orderBy('processo_id')->orderBy('numero')->get()
            ->map(fn ($it) => [$it->processo_id, $it->numero, $it->material_servico, $it->codigo_catalogo, $it->descricao, $it->unidade_medida, $this->valor($it->quantidade, 4),
                $this->valor($it->valor_unitario_estimado, 4), $this->valor($it->valor_unitario_homologado, 4), $it->situacao, $it->fornecedorVencedor?->documento])->all();

        return [['processo_id', 'item', 'material_servico', 'codigo_catmat_catser', 'descricao', 'unidade', 'quantidade', 'valor_unitario_estimado', 'valor_unitario_homologado', 'situacao', 'documento_fornecedor'], $linhas];
    }

    private function contratos(Orgao $o, Carbon $i, Carbon $f): array
    {
        $linhas = Contrato::withoutGlobalScope('orgao')->with(['fornecedor' => fn ($q) => $q->withoutGlobalScope('orgao')])->where('orgao_id', $o->id)->whereBetween('assinatura', [$i->toDateString(), $f->toDateString()])->orderBy('assinatura')->get()
            ->map(fn ($c) => [$c->id, $c->processo_id, $c->identificacao(), $c->objeto, $c->fornecedor?->documento, $c->fornecedor?->razao_social, $this->valor($c->valor_inicial), $this->valor($c->valor_global),
                $c->assinatura?->toDateString(), $c->vigencia_inicio?->toDateString(), $c->vigencia_fim?->toDateString(), $c->situacao, $c->numero_controle_pncp])->all();

        return [['id', 'processo_id', 'numero_ano', 'objeto', 'documento_fornecedor', 'fornecedor', 'valor_inicial', 'valor_global', 'assinatura', 'vigencia_inicio', 'vigencia_fim', 'situacao', 'numero_controle_pncp'], $linhas];
    }

    private function termos(Orgao $o, Carbon $i, Carbon $f): array
    {
        $linhas = TermoContrato::withoutGlobalScope('orgao')->where('orgao_id', $o->id)->whereBetween('assinatura', [$i->toDateString(), $f->toDateString()])->orderBy('assinatura')->get()
            ->map(fn ($t) => [$t->contrato_id, $t->tipo, $t->numero, $t->assinatura?->toDateString(), $this->valor($t->valor_acrescimo), $this->valor($t->valor_supressao), $this->valor($t->valor_reajuste), $t->nova_vigencia_fim?->toDateString(), $t->reequilibrio ? 'S' : 'N', $t->justificativa])->all();

        return [['contrato_id', 'tipo', 'numero', 'assinatura', 'valor_acrescimo', 'valor_supressao', 'valor_reajuste', 'nova_vigencia_fim', 'reequilibrio', 'justificativa'], $linhas];
    }

    private function atas(Orgao $o, Carbon $i, Carbon $f): array
    {
        $linhas = Ata::withoutGlobalScope('orgao')->with(['fornecedor' => fn ($q) => $q->withoutGlobalScope('orgao')])->where('orgao_id', $o->id)->whereBetween('assinatura', [$i->toDateString(), $f->toDateString()])->orderBy('assinatura')->get()
            ->map(fn ($a) => [$a->id, $a->processo_id, $a->identificacao(), $a->fornecedor?->documento, $a->assinatura?->toDateString(), $a->vigencia_inicio?->toDateString(), $a->vigencia_fim?->toDateString(), $a->prorrogada ? 'S' : 'N', $a->situacao])->all();

        return [['id', 'processo_id', 'numero_ano', 'documento_fornecedor', 'assinatura', 'vigencia_inicio', 'vigencia_fim', 'prorrogada', 'situacao'], $linhas];
    }

    private function valor(mixed $v, int $casas = 2): string
    {
        return $v === null ? '' : number_format((float) $v, $casas, ',', '');
    }

    private function csv(array $cabecalho, array $linhas): string
    {
        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, $cabecalho, ';', '"', '');
        foreach ($linhas as $l) {
            // Neutraliza fórmula em planilha (=, +, -, @ no início da célula).
            fputcsv($h, array_map(fn ($c) => is_string($c) && $c !== '' && in_array($c[0], ['=', '+', '-', '@'], true) && ! is_numeric(str_replace(',', '.', $c)) ? "'".$c : $c, $l), ';', '"', '');
        }
        rewind($h);
        $s = stream_get_contents($h);
        fclose($h);

        return $s;
    }
}
