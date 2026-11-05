<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Livro de ocorrências · contrato {{ $c->identificacao() }}</title>
@include('ocorrencias._estilo')
</head>
<body>
<div class="cab"><b>{{ $c->orgao->nome }}</b><br>CNPJ {{ $c->orgao->cnpj }}</div>
<h1>Livro de ocorrências do contrato nº {{ $c->identificacao() }}</h1>
<p>Contratada: {{ $c->fornecedor->razao_social }} · Gestor: {{ $c->gestor?->name ?? '—' }} · Fiscal: {{ $c->fiscal?->name ?? '—' }}<br>
Registro do art. 117, § 1º, da Lei nº 14.133/2021. Encadeamento por hash: {{ $integro ? 'íntegro, nenhuma anotação foi alterada' : 'ATENÇÃO: o encadeamento não confere' }}.</p>

<table>
    <thead><tr><th style="width:6%">Nº</th><th style="width:14%">Data</th><th>Anotação</th><th style="width:22%">Registro</th></tr></thead>
    <tbody>
        @forelse ($ocorrencias as $o)
            <tr>
                <td>{{ $o->numero }}</td>
                <td>{{ $o->ocorrida_em->format('d/m/Y') }}</td>
                <td><b>{{ $o->tipoRotulo() }}</b>@if ($o->retifica_id) (retifica a nº {{ $ocorrencias->firstWhere('id', $o->retifica_id)?->numero }})@endif<br>{{ $o->descricao }}@if ($o->providencias)<br><i>Providências:</i> {{ $o->providencias }}@endif @if ($o->prazo_regularizacao)<br><i>Regularizar até</i> {{ $o->prazo_regularizacao->format('d/m/Y') }}@endif</td>
                <td>{{ $o->autor_nome }} ({{ $o->autor_papel }})<br>{{ $o->registrada_em->format('d/m/Y H:i') }}<br><span style="font-size:7pt">{{ substr($o->hash, 0, 16) }}…</span></td>
            </tr>
        @empty
            <tr><td colspan="4">Nenhuma anotação.</td></tr>
        @endforelse
    </tbody>
</table>

<div class="rod">Gerado pelo NexPlanGov em {{ now()->format('d/m/Y H:i') }}. Desenvolvido por NexMind.</div>
</body>
</html>
