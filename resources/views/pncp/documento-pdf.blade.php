<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>{{ $titulo }}</title>
<style>
    @page { margin: 2.2cm 2cm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5pt; color: #1b2a33; line-height: 1.45; }
    .cab { border-bottom: 2px solid #1E5470; padding-bottom: 8px; margin-bottom: 18px; }
    .cab b { font-size: 12pt; color: #1E5470; }
    h1 { font-size: 15pt; color: #1E5470; margin: 0 0 4px; }
    h2 { font-size: 11pt; color: #1E5470; margin: 16px 0 4px; }
    .sub { color: #4a5b66; margin: 0 0 14px; }
    p { margin: 0 0 8px; white-space: pre-wrap; }
    table { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 9.5pt; }
    th, td { border: 1px solid #c9d6de; padding: 5px 6px; text-align: left; vertical-align: top; }
    th { background: #D1ECFF; }
    td.n { text-align: right; white-space: nowrap; }
    .rod { position: fixed; bottom: -1.4cm; left: 0; right: 0; font-size: 8pt; color: #6b7c87; border-top: 1px solid #c9d6de; padding-top: 4px; }
</style>
</head>
<body>
<div class="cab"><b>{{ $orgao->nome }}</b><br>CNPJ {{ $orgao->cnpj }}@if ($orgao->municipio) · {{ $orgao->municipio }}/{{ $orgao->uf }}@endif</div>
<h1>{{ $titulo }}</h1>
@if (! empty($subtitulo))<p class="sub">{{ $subtitulo }}</p>@endif
@foreach ($secoes as $rotulo => $texto)
    @if (filled($texto))
        <h2>{{ $rotulo }}</h2>
        <p>{{ $texto }}</p>
    @endif
@endforeach
@if (! empty($tabela))
    <h2>{{ $tabela['titulo'] }}</h2>
    <table>
        <thead><tr>@foreach ($tabela['colunas'] as $c)<th>{{ $c }}</th>@endforeach</tr></thead>
        <tbody>@foreach ($tabela['linhas'] as $l)<tr>@foreach ($l as $i => $v)<td class="{{ is_numeric(str_replace(['.', ','], '', (string) $v)) && $i > 0 ? 'n' : '' }}">{{ $v }}</td>@endforeach</tr>@endforeach</tbody>
    </table>
@endif
<div class="rod">Gerado pelo NexPlanGov em {{ now()->format('d/m/Y H:i') }} · Lei nº 14.133/2021 · {{ $rodape ?? '' }}</div>
</body>
</html>
