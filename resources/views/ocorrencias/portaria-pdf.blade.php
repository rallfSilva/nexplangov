<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Designação de gestor e fiscal · contrato {{ $c->identificacao() }}</title>
@include('ocorrencias._estilo')
</head>
<body>
<div class="cab"><b>{{ $c->orgao->nome }}</b><br>CNPJ {{ $c->orgao->cnpj }}</div>
<h1>{{ $c->designacao_ato ?: 'Minuta de ato de designação' }}</h1>

<p>Designa os representantes da Administração para acompanhar e fiscalizar a execução do contrato nº {{ $c->identificacao() }}, com fundamento no art. 117 da Lei nº 14.133/2021.</p>

<table>
    <tr><th style="width:30%">Contrato</th><td>{{ $c->identificacao() }}@if ($c->processo) · processo {{ $c->processo->identificacao() }}@endif</td></tr>
    <tr><th>Contratada</th><td>{{ $c->fornecedor->razao_social }} · {{ $c->fornecedor->documento }}</td></tr>
    <tr><th>Objeto</th><td>{{ $c->objeto }}</td></tr>
    <tr><th>Vigência</th><td>{{ $c->vigencia_inicio->format('d/m/Y') }} a {{ $c->vigencia_fim->format('d/m/Y') }}</td></tr>
    <tr><th>Gestor do contrato</th><td>{{ $c->gestor?->name ?? 'a definir' }}</td></tr>
    <tr><th>Fiscal do contrato</th><td>{{ $c->fiscal?->name ?? 'a definir' }}</td></tr>
</table>

<p>Art. 1º Ficam designados o gestor e o fiscal acima, que não podem ser a mesma pessoa (segregação de funções, art. 7º, § 1º).</p>
<p>Art. 2º Cabe ao fiscal anotar em registro próprio todas as ocorrências da execução e determinar o necessário para regularizar as faltas ou os defeitos observados, informando aos superiores, em tempo hábil, o que exceder a sua competência (art. 117, §§ 1º e 2º).</p>
<p>Art. 3º Este ato entra em vigor na data de sua assinatura.</p>

<div class="assin"><span>{{ $c->designador?->name ?? 'Autoridade competente' }}@if ($c->designacao_em)<br>{{ $c->designacao_em->format('d/m/Y') }}@endif</span></div>

<div class="rod">Gerado pelo NexPlanGov em {{ now()->format('d/m/Y H:i') }}. Desenvolvido por NexMind.</div>
</body>
</html>
