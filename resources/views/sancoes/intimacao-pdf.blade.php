<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Notificação {{ $ps->numero }}</title>
<style>
    @page { margin: 2.2cm 2cm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #1b2a33; line-height: 1.5; }
    .cab { border-bottom: 2px solid #1E5470; padding-bottom: 8px; margin-bottom: 16px; }
    .cab b { font-size: 12pt; color: #1E5470; }
    h1 { font-size: 14pt; color: #1E5470; margin: 0 0 10px; }
    h2 { font-size: 11pt; color: #1E5470; margin: 14px 0 4px; }
    p { margin: 0 0 8px; text-align: justify; }
    ul { margin: 0 0 8px 18px; padding: 0; }
    .rod { position: fixed; bottom: -1.4cm; left: 0; right: 0; font-size: 8pt; color: #6b7c87; border-top: 1px solid #c9d6de; padding-top: 4px; }
</style>
</head>
<body>
<div class="cab"><b>{{ $ps->orgao->nome }}</b><br>CNPJ {{ $ps->orgao->cnpj }}</div>
<h1>Notificação para apresentação de defesa · Processo sancionador {{ $ps->numero }}</h1>

<p><b>Destinatário:</b> {{ $ps->fornecedor->razao_social }} · {{ $ps->fornecedor->documento }}@if ($ps->contrato) · Contrato nº {{ $ps->contrato->identificacao() }}@endif</p>

<p>Fica V. Sa. notificada da instauração de processo de responsabilização, nos termos dos arts. 155 a 158 da Lei nº 14.133/2021, pelos fatos e fundamentos a seguir.</p>

<h2>Fatos</h2>
<p style="white-space:pre-line">{{ $ps->fatos }}</p>

<h2>Infrações apontadas (art. 155)</h2>
<ul>@foreach ($ps->infracoes as $i)<li>Inciso {{ $i }}: {{ $infracoes[$i] }}.</li>@endforeach</ul>

<h2>Sanção em tese</h2>
<p>{{ \App\Models\SancaoFornecedor::TIPOS[$ps->sancao_proposta] }}. A sanção, se houver, só será aplicada depois da análise da defesa, considerando a natureza e a gravidade da infração, as peculiaridades do caso, as circunstâncias agravantes ou atenuantes, os danos causados e a existência de programa de integridade (art. 156, § 1º).</p>

<h2>Prazo e forma da defesa</h2>
<p>V. Sa. tem <b>15 (quinze) dias úteis</b>, contados da intimação em {{ $ps->intimado_em->format('d/m/Y') }}, ou seja, <b>até {{ $ps->prazo_defesa->format('d/m/Y') }}</b>, para apresentar defesa escrita e, se quiser, especificar as provas que pretende produzir (arts. 157 e 158). Os autos podem ser consultados no órgão, e a defesa pode ser feita por advogado.</p>
@if ($ps->comissao)<p>O processo é conduzido por comissão de servidores designada nos termos do art. 158.</p>@endif

<p style="margin-top:28px">{{ $ps->orgao->municipio ? $ps->orgao->municipio.', ' : '' }}{{ $ps->intimado_em->translatedFormat('d \d\e F \d\e Y') }}.</p>

<div class="rod">NexPlanGov · Desenvolvido por NexMind (nexmind.net.br) · Lei nº 14.133/2021</div>
</body>
</html>
