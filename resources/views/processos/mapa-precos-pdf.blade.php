<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Mapa de preços</title>
<style>
    @page { margin: 2cm 1.6cm 2.2cm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #1b2a33; line-height: 1.4; }
    .cab { border-bottom: 2px solid #1E5470; padding-bottom: 8px; margin-bottom: 14px; }
    .cab b { font-size: 12pt; color: #1E5470; }
    h1 { font-size: 15pt; color: #1E5470; margin: 0 0 4px; }
    h2 { font-size: 11pt; color: #1E5470; margin: 18px 0 4px; page-break-after: avoid; }
    .sub { color: #4a5b66; margin: 0 0 12px; }
    p { margin: 0 0 6px; }
    table { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 8.5pt; }
    th, td { border: 1px solid #c9d6de; padding: 4px 5px; text-align: left; vertical-align: top; }
    th { background: #D1ECFF; }
    td.n, th.n { text-align: right; white-space: nowrap; }
    .alerta { color: #9A3412; }
    .fora { color: #6b7c87; }
    .ia { font-size: 7.5pt; color: #34729C; }
    .just { background: #F4FAFD; border-left: 3px solid #34729C; padding: 6px 8px; white-space: pre-line; }
</style>
</head>
<body>
@php
    $nomes = ['minimo' => 'Mínimo', 'media' => 'Média', 'mediana' => 'Mediana', 'media_ponderada' => 'Média ponderada'];
    $total = $processo->itens->sum(fn ($i) => (float) $i->valor_total_estimado);
@endphp
<div class="cab"><b>{{ $processo->orgao->nome }}</b><br>CNPJ {{ $processo->orgao->cnpj }}@if ($processo->orgao->municipio) · {{ $processo->orgao->municipio }}/{{ $processo->orgao->uf }}@endif</div>
<h1>Mapa de preços</h1>
<p class="sub">Processo {{ $processo->identificacao() }} · {{ $processo->objeto }}<br>Pesquisa de preços conforme o art. 23 da Lei nº 14.133/2021. Gerado em {{ now()->format('d/m/Y H:i') }}.</p>

<h2>Resumo por item</h2>
<table>
    <thead><tr><th>Item</th><th>Descrição</th><th class="n">Qtd.</th>@foreach ($opcoes['colunas'] as $k)<th class="n">{{ $nomes[$k] }}</th>@endforeach<th>Método</th><th class="n">Unitário estimado</th><th class="n">Total estimado</th></tr></thead>
    <tbody>
        @foreach ($processo->itens as $item)
            @php($r = $precos[$item->id])
            <tr>
                <td>{{ $item->numero }}</td>
                <td>{{ $item->descricao }}@if ($item->codigo_catalogo)<br><span class="fora">{{ $item->material_servico === 'S' ? 'CATSER' : 'CATMAT' }} {{ $item->codigo_catalogo }}</span>@endif @if ($r['discrepante'])<br><span class="alerta">Preços discrepantes (CV {{ numero_br($r['coeficiente_variacao']) }}%)</span>@endif</td>
                <td class="n">{{ numero_br($item->quantidade, 0) }} {{ $item->unidade_medida }}</td>
                @foreach ($opcoes['colunas'] as $k)<td class="n">{{ reais($r['estatisticas'][$k], 2) }}</td>@endforeach
                <td>{{ \App\Licitacao\Regras\PesquisaPrecos::METODOS[$item->metodo_preco] ?? $item->metodo_preco }}</td>
                <td class="n">{{ reais($item->valor_unitario_estimado, 4) }}</td>
                <td class="n">{{ reais($item->valor_total_estimado) }}</td>
            </tr>
        @endforeach
        <tr><td colspan="{{ 5 + count($opcoes['colunas']) }}"><b>Valor total estimado</b></td><td class="n"><b>{{ reais($total) }}</b></td></tr>
    </tbody>
</table>

@if ($opcoes['cotacoes'] || $opcoes['justificativa'])
    @foreach ($processo->itens as $item)
        @php($r = $precos[$item->id])
        <h2>Item {{ $item->numero }} · {{ \Illuminate\Support\Str::limit($item->descricao, 120) }}</h2>
        @foreach ($r['alertas'] as $a)<p class="alerta">{{ $a }}</p>@endforeach
        @if ($opcoes['cotacoes'])
            <table>
                <thead><tr><th>Fonte</th><th>Referência</th><th>Data</th><th class="n">Qtd.</th><th class="n">Valor unitário</th><th>Situação</th></tr></thead>
                <tbody>
                    @forelse ($item->cotacoes as $i => $c)
                        @continue(isset($r['descartadas'][$i]) && ! $opcoes['descartadas'])
                        <tr @class(['fora' => isset($r['descartadas'][$i])])>
                            <td>{{ $c->fonteEnum()->rotulo() }}</td>
                            <td>{{ $c->referencia }}@if ($c->documento_fonte)<br>{{ $c->documento_fonte }}@endif
                                @if ($c->descricao_fonte)<br><span class="fora">{{ \Illuminate\Support\Str::limit($c->descricao_fonte, 200) }}@if ($c->unidade_fonte) · {{ $c->unidade_fonte }}@endif</span>@endif
                                @if ($c->url_fonte)<br><span class="fora">{{ $c->url_fonte }}</span>@endif
                                @if ($c->daIa())<br><span class="ia">Trazida pela NexMind IA{{ $c->analise_ia ? ': '.$c->analise_ia : '' }}</span>@endif
                            </td>
                            <td>{{ $c->data_cotacao->format('d/m/Y') }}</td>
                            <td class="n">{{ $c->quantidade ? numero_br($c->quantidade, 0) : '' }}</td>
                            <td class="n">{{ reais($c->valor_unitario, 4) }}</td>
                            <td>{{ isset($r['descartadas'][$i]) ? 'Fora do cálculo: '.$r['descartadas'][$i] : 'Válida' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">Sem cotações.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif
        @if ($opcoes['justificativa'] && $item->justificativa_precos)
            <p style="margin-top:6px"><b>Justificativa</b></p>
            <div class="just">{{ $item->justificativa_precos }}</div>
        @endif
    @endforeach
@endif
</body>
</html>
