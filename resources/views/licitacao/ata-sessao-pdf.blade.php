<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ata da sessão pública · {{ $p->identificacao() }}</title>
<style>
    @page { margin: 2.2cm 2cm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #1b2a33; line-height: 1.45; }
    .cab { border-bottom: 2px solid #1E5470; padding-bottom: 8px; margin-bottom: 16px; }
    .cab b { font-size: 12pt; color: #1E5470; }
    h1 { font-size: 15pt; color: #1E5470; margin: 0 0 4px; }
    h2 { font-size: 11pt; color: #1E5470; margin: 16px 0 4px; }
    h3 { font-size: 10pt; margin: 10px 0 2px; }
    .sub { color: #4a5b66; margin: 0 0 12px; }
    p { margin: 0 0 6px; }
    table { width: 100%; border-collapse: collapse; margin-top: 4px; font-size: 9pt; }
    th, td { border: 1px solid #c9d6de; padding: 4px 5px; text-align: left; vertical-align: top; }
    th { background: #D1ECFF; }
    td.n { text-align: right; white-space: nowrap; }
    .rod { position: fixed; bottom: -1.4cm; left: 0; right: 0; font-size: 8pt; color: #6b7c87; border-top: 1px solid #c9d6de; padding-top: 4px; }
</style>
</head>
<body>
@php($moeda = fn ($v, $c = 4) => number_format((float) $v, $c, ',', '.'))
@php($desconto = $p->criterio_julgamento === \App\Licitacao\Enums\CriterioJulgamento::MaiorDesconto->value)
<div class="cab"><b>{{ $p->orgao->nome }}</b><br>CNPJ {{ $p->orgao->cnpj }}@if ($p->orgao->municipio) · {{ $p->orgao->municipio }}/{{ $p->orgao->uf }}@endif</div>
<h1>Ata da sessão pública</h1>
<p class="sub">{{ $p->modalidadeEnum()->rotulo() }} nº {{ $p->identificacao() }} · {{ \App\Licitacao\Enums\CriterioJulgamento::from($p->criterio_julgamento)->rotulo() }} · disputa {{ \App\Licitacao\Enums\ModoDisputa::from($p->modo_disputa)->rotulo() }}</p>

<h2>Objeto</h2>
<p>{{ $p->objeto }}</p>

<p>Sessão encerrada em {{ $p->sessao_encerrada_em->format('d/m/Y \à\s H:i') }} por {{ $encerrou?->name ?? 'agente de contratação' }}, que conduziu os trabalhos conforme a Lei nº 14.133/2021.</p>
@if ($p->modalidadeEnum()->ehPresencial())
    <p>Sessão presencial (art. 17, § 2º), gravada em áudio e vídeo (art. 17, § 5º)@if ($p->gravacao_link): {{ $p->gravacao_link }}@endif @if ($p->gravacao_hash) · arquivo {{ $p->gravacao_nome }} (SHA-256 {{ $p->gravacao_hash }})@endif.</p>
@endif

@foreach ($p->itens as $item)
    <h2>Item {{ $item->numero }}: {{ \Illuminate\Support\Str::limit($item->descricao, 120) }}</h2>
    <p>{{ $moeda($item->quantidade, 2) }} {{ $item->unidade_medida }} · valor estimado {{ $p->orcamento_sigiloso ? 'sigiloso' : 'R$ '.$moeda($item->valor_unitario_estimado) }}</p>
    @if ($item->propostas->isEmpty())
        <p>Sem propostas.</p>
    @else
        <table>
            <thead><tr><th>Licitante</th><th>ME/EPP</th><th class="n">{{ $desconto ? 'Desconto inicial (%)' : 'Proposta' }}</th><th class="n">{{ $desconto ? 'Desconto final (%)' : 'Valor final' }}</th><th>Situação</th><th>Habilitação</th></tr></thead>
            <tbody>
            @foreach ($item->propostas->sortBy('id') as $pr)
                <tr>
                    <td>{{ $pr->fornecedor->razao_social }}<br>{{ $pr->fornecedor->documento }}</td>
                    <td>{{ $pr->me_epp ? 'Sim' : 'Não' }}</td>
                    <td class="n">{{ $moeda($pr->valor) }}</td>
                    <td class="n">{{ $moeda($pr->valorVigente()) }}</td>
                    <td>{{ ['vencedora' => 'Vencedora', 'classificada' => 'Classificada', 'desclassificada' => 'Desclassificada', 'recusada' => 'Recusada'][$pr->situacao] ?? $pr->situacao }}@if ($pr->motivo)<br>{{ $pr->motivo }}@endif @if ($pr->desempate_criterio)<br>Desempate: {{ \App\Licitacao\Regras\Julgamento::DESEMPATE[$pr->desempate_criterio] ?? $pr->desempate_criterio }}. {{ $pr->desempate_justificativa }}@endif</td>
                    <td>{{ ['pendente' => 'Não analisada', 'habilitado' => 'Habilitada', 'inabilitado' => 'Inabilitada'][$pr->habilitacao] ?? $pr->habilitacao }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @php($lances = $item->propostas->flatMap(fn ($pr) => $pr->lances->map(fn ($l) => ['quando' => $l->created_at, 'quem' => $pr->fornecedor->razao_social, 'valor' => $l->valor]))->sortBy('quando'))
        @if ($lances->isNotEmpty())
            <h3>Lances, em ordem de registro</h3>
            <table>
                <thead><tr><th>Hora</th><th>Licitante</th><th class="n">Lance</th></tr></thead>
                <tbody>@foreach ($lances as $l)<tr><td>{{ $l['quando']?->format('d/m/Y H:i:s') }}</td><td>{{ $l['quem'] }}</td><td class="n">{{ $moeda($l['valor']) }}</td></tr>@endforeach</tbody>
            </table>
        @endif
        @if ($item->adjudicado_em)<p>Adjudicado em {{ $item->adjudicado_em->format('d/m/Y H:i') }} ao valor unitário de {{ $moeda($item->valor_unitario_homologado) }}.</p>@endif
    @endif
@endforeach

<h2>Intenções de recurso <span style="font-weight:normal">(art. 165, § 1º, I)</span></h2>
@if ($p->recursos->isEmpty())
    <p>Aberta a oportunidade, nenhum licitante manifestou intenção de recorrer.</p>
@else
    <table>
        <thead><tr><th>Licitante</th><th>Contra</th><th>Item</th><th>Motivo</th><th>Prazo das razões</th></tr></thead>
        <tbody>@foreach ($p->recursos as $r)<tr><td>{{ $r->fornecedor->razao_social }}</td><td>{{ \App\Models\Recurso::TIPOS[$r->tipo] }}</td><td>{{ $r->item?->numero ?? 'Todos' }}</td><td>{{ $r->intencao_motivo }}</td><td>{{ $r->prazo_razoes?->format('d/m/Y') }}</td></tr>@endforeach</tbody>
    </table>
    <p>As razões devem ser apresentadas em até 3 dias úteis contados desta ata, e os demais licitantes podem apresentar contrarrazões em igual prazo, contado da divulgação da interposição (art. 165, I e § 4º). Fica assegurada vista dos autos (art. 165, § 5º).</p>
@endif

@if ($p->sessao_reaberta_motivo)
    <h2>Reabertura anterior</h2>
    <p>{{ $p->sessao_reaberta_motivo }}</p>
@endif

<p style="margin-top:16px">Nada mais havendo, foi encerrada a sessão e lavrada esta ata, que vai publicada no processo.</p>
<div class="rod">Ata gerada pelo NexPlanGov em {{ now()->format('d/m/Y H:i') }} · Lei nº 14.133/2021 · {{ $p->identificacao() }}</div>
</body>
</html>
