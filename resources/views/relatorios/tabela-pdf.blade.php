<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>{{ $titulo }}</title>
@include('ocorrencias._estilo')
<style>td.n { text-align: right; white-space: nowrap; }</style>
</head>
<body>
<div class="cab"><b>{{ $orgao?->nome ?? 'NexPlanGov' }}</b>@if ($orgao)<br>CNPJ {{ $orgao->cnpj }}@endif</div>
<h1>{{ $titulo }}</h1>
<table>
    <thead><tr>@foreach ($cabecalho as $c)<th>{{ $c }}</th>@endforeach</tr></thead>
    <tbody>
        @forelse ($linhas as $l)
            <tr>@foreach ($l as $v)<td class="{{ is_int($v) || is_float($v) ? 'n' : '' }}">{{ is_float($v) ? number_format($v, 2, ',', '.') : ($v ?? '—') }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ count($cabecalho) }}">Sem dados no período.</td></tr>
        @endforelse
    </tbody>
</table>
<p class="small" style="margin-top:10px">Gerado em {{ now()->format('d/m/Y H:i') }} pelo NexPlanGov. Indicadores de governança das contratações (art. 11, parágrafo único, da Lei nº 14.133/2021).</p>
</body>
</html>
