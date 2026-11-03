<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Autorização de adesão {{ $a->protocolo }}</title>
<style>
    @page { margin: 2.2cm 2cm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #1b2a33; line-height: 1.5; }
    .cab { border-bottom: 2px solid #1E5470; padding-bottom: 8px; margin-bottom: 16px; }
    .cab b { font-size: 12pt; color: #1E5470; }
    h1 { font-size: 14pt; color: #1E5470; margin: 0 0 10px; }
    table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 9.5pt; }
    th, td { border: 1px solid #c9d6de; padding: 4px 6px; text-align: left; vertical-align: top; }
    th { background: #D1ECFF; width: 32%; }
    .qr { margin-top: 18px; }
    .qr td { border: 0; vertical-align: middle; }
    .rod { position: fixed; bottom: -1.4cm; left: 0; right: 0; font-size: 8pt; color: #6b7c87; border-top: 1px solid #c9d6de; padding-top: 4px; }
</style>
</head>
<body>
@php($ai = $a->ataItem)
@php($ata = $ai->ata)
<div class="cab"><b>{{ $ata->orgao->nome }}</b><br>CNPJ {{ $ata->orgao->cnpj }} · Órgão gerenciador</div>
<h1>Autorização de adesão à ata de registro de preços nº {{ $ata->identificacao() }}</h1>

<p>Com fundamento no art. 86, § 2º, da Lei nº 14.133/2021, o órgão gerenciador <b>AUTORIZA</b> a adesão do órgão não participante abaixo, observados os limites dos §§ 4º e 5º do mesmo artigo, depois de recebido o aceite do fornecedor beneficiário.</p>

<table>
    <tr><th>Protocolo</th><td>{{ $a->protocolo }}</td></tr>
    <tr><th>Órgão aderente</th><td>{{ $a->aderente_nome }} · CNPJ {{ $a->aderente_cnpj }} · {{ \App\Models\AtaAdesao::ESFERAS[$a->aderente_esfera] ?? $a->aderente_esfera }}@if ($a->aderente_municipio) · {{ $a->aderente_municipio }}/{{ $a->aderente_uf }}@endif</td></tr>
    <tr><th>Processo de origem</th><td>{{ $ata->processo->identificacao() }} · {{ $ata->processo->objeto }}</td></tr>
    <tr><th>Fornecedor beneficiário</th><td>{{ $ata->fornecedor->razao_social }} · {{ $ata->fornecedor->documento }}</td></tr>
    <tr><th>Item</th><td>{{ $ai->item->numero }}: {{ $ai->item->descricao }}</td></tr>
    <tr><th>Quantidade autorizada</th><td>{{ number_format((float) $a->quantidade, 4, ',', '.') }} {{ $ai->item->unidade_medida }} a R$ {{ number_format((float) $ai->valor_unitario, 4, ',', '.') }}</td></tr>
    <tr><th>Aceite do fornecedor</th><td>{{ $a->fornecedor_aceite_em->format('d/m/Y H:i') }} · {{ $a->fornecedor_aceite_representante }} ({{ $a->fornecedor_aceite_via === 'link' ? 'resposta pelo link enviado ao fornecedor' : 'documento registrado pelo gerenciador' }})</td></tr>
    <tr><th>Vigência da ata</th><td>até {{ $ata->vigencia_fim->format('d/m/Y') }}</td></tr>
    <tr><th>Prazo para contratar</th><td><b>até {{ $a->prazo_contratacao->format('d/m/Y') }}</b>, conforme o regulamento e dentro da vigência da ata</td></tr>
</table>

<p>Cabe ao órgão aderente a instrução do próprio processo, com a justificativa da vantagem e a demonstração de que os preços estão compatíveis com os de mercado (art. 86, § 2º, I e II), a formalização da contratação e a sua publicação no PNCP (art. 94).</p>

<p>Autorizado em {{ $a->decidida_em->format('d/m/Y H:i') }} por {{ $a->decisor?->name }}.</p>

<table class="qr"><tr>
    <td style="width:140px"><img src="{{ $qr }}" width="120" height="120" alt="QR Code de verificação"></td>
    <td>Confira a autenticidade em<br><b>{{ $url }}</b><br>Código de verificação: <b>{{ $a->codigo_verificacao }}</b></td>
</tr></table>

<div class="rod">NexPlanGov · Desenvolvido por NexMind (nexmind.net.br) · Lei nº 14.133/2021</div>
</body>
</html>
