<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Edital do processo {{ $p->identificacao() }}</title>
@include('ocorrencias._estilo')
<style>
    .doc { page-break-before: always; }
    .meta { color: #4a5b68; font-size: 8.5pt; margin-bottom: 10px; }
    h2 { font-size: 11pt; color: #1E5470; margin: 14px 0 4px; }
    .hash { font-family: DejaVu Sans Mono, monospace; font-size: 7.5pt; word-break: break-all; }
    .termo { border: 1px solid #c9d6de; padding: 12px 14px; margin-top: 12px; }
    .minuta { border: 1px solid #E0A458; background: #FFF7EC; color: #7A3B0B; padding: 8px 12px; margin: 10px 0; }
    .texto { text-align: justify; }
</style>
</head>
<body>
@php($concluido = $edital?->situacao === 'concluido')
<div class="cab"><b>{{ $p->orgao?->nome }}</b><br>CNPJ {{ $p->orgao?->cnpj }}</div>
<h1>Caderno do edital · {{ $p->modalidadeEnum()->rotulo() }} nº {{ $p->identificacao() }}</h1>
<p><b>Objeto:</b> {{ $p->objeto }}<br>
<b>Critério de julgamento:</b> {{ \App\Licitacao\Enums\CriterioJulgamento::from($p->criterio_julgamento)->rotulo() }}@if ($p->abertura_propostas_em) · <b>Abertura:</b> {{ $p->abertura_propostas_em->format('d/m/Y H:i') }}@endif<br>
<span class="meta">Gerado em {{ now()->format('d/m/Y H:i') }} com a última versão salva de cada peça. Base legal: Lei nº 14.133/2021, art. 25 (edital e anexos) e art. 18 (peças da fase preparatória).</span></p>

@if (! $concluido || $pendencias !== [])
    <div class="minuta"><b>MINUTA PARA REVISÃO.</b> Este caderno ainda não é a versão final do edital: revise com a assessoria jurídica (art. 53) antes de divulgar no PNCP (art. 54).</div>
@endif

<h2>Sumário</h2>
<table>
    <thead><tr><th style="width:14%">Parte</th><th>Documento</th><th style="width:30%">Situação</th></tr></thead>
    <tbody>
        <tr><td>Edital</td><td>Edital da licitação</td><td>{{ $edital ? "versão {$edital->versao}, ".($concluido ? 'concluída' : 'em elaboração') : 'não elaborado' }}</td></tr>
        @foreach ($anexos as $a)
            <tr><td>Anexo {{ $a['numero'] }}</td><td>{{ $a['titulo'] }}</td><td>@if (! $a['tipo']) modelos padrão do sistema @elseif ($a['doc']) versão {{ $a['doc']->versao }}, {{ $a['doc']->situacao === 'concluido' ? 'concluída' : 'em elaboração' }} @else não elaborado @endif</td></tr>
        @endforeach
    </tbody>
</table>

@if ($pendencias !== [])
    <h2>O que ainda falta</h2>
    <ul>@foreach ($pendencias as $x)<li>{{ $x }}</li>@endforeach</ul>
@endif

<div class="doc">
    <h1>Edital</h1>
    @if (! $edital)
        <div class="termo">O edital ainda não foi salvo no sistema. Use "Gerar edital completo" na peça Minuta do edital.</div>
    @elseif ($edital->origem === 'arquivo')
        <div class="termo"><b>Edital enviado como arquivo.</b> {{ $edital->arquivo_nome }}, SHA-256 <span class="hash">{{ $edital->arquivo_hash }}</span>. O arquivo original está no processo eletrônico.</div>
    @else
        <div class="texto">{!! nl2br(e(trim((string) ($edital->conteudo['texto'] ?? '')))) !!}</div>
    @endif
</div>

@foreach ($anexos as $a)
    <div class="doc">
        <h1>Anexo {{ $a['numero'] }} · {{ $a['titulo'] }}</h1>
        @if (! $a['tipo'])
            <div class="meta">Modelos de declarações exigidas do licitante (Lei nº 14.133/2021). Preencher em papel timbrado do licitante.</div>
            @foreach ([
                'Declaração de cumprimento dos requisitos de habilitação (art. 63, I)' => 'Declaro, sob as penas da lei, que a empresa [razão social], CNPJ [informe], atende aos requisitos de habilitação deste edital e responde pela veracidade das informações prestadas.',
                'Declaração de reserva de cargos (art. 63, IV)' => 'Declaro que a empresa cumpre as exigências de reserva de cargos para pessoa com deficiência e para reabilitado da Previdência Social, previstas em lei e em outras normas específicas.',
                'Declaração de proposta compatível com os custos trabalhistas (art. 63, § 1º)' => 'Declaro que a proposta econômica compreende a integralidade dos custos para atendimento dos direitos trabalhistas assegurados na Constituição Federal, nas leis trabalhistas, nas normas infralegais, nas convenções coletivas de trabalho e nos termos de ajustamento de conduta vigentes na data de entrega das propostas.',
                'Declaração de inexistência de impedimento (art. 14)' => 'Declaro que a empresa, seus sócios e dirigentes não estão impedidos de licitar ou contratar com a Administração Pública, nos termos do art. 14 da Lei nº 14.133/2021.',
                'Declaração de não emprego de menor (art. 68, VI)' => 'Declaro que a empresa não emprega menor de 18 anos em trabalho noturno, perigoso ou insalubre, nem menor de 16 anos, salvo na condição de aprendiz, a partir de 14 anos (Constituição Federal, art. 7º, XXXIII).',
                'Declaração de enquadramento como ME ou EPP (art. 4º)' => 'Declaro, para fins do tratamento diferenciado da Lei Complementar nº 123/2006, que a empresa se enquadra como [microempresa ou empresa de pequeno porte] e não está em nenhuma das hipóteses de exclusão do art. 3º, § 4º, daquela lei, e que no ano-calendário de realização da licitação ainda não celebrou contratos com a Administração Pública cujos valores somados extrapolem a receita bruta máxima admitida para fins de enquadramento como empresa de pequeno porte (art. 4º, § 2º).',
            ] as $titulo => $texto)
                <h2>{{ $titulo }}</h2>
                <div class="texto">{{ $texto }}</div>
                <p class="small">[Local e data]. [Nome, cargo e assinatura do representante legal].</p>
            @endforeach
        @elseif (! $a['doc'])
            <div class="termo">Peça ainda não elaborada no processo.</div>
        @elseif ($a['doc']->origem === 'arquivo')
            <div class="termo"><b>Peça enviada como arquivo.</b> {{ $a['doc']->arquivo_nome }} ({{ number_format(($a['doc']->arquivo_tamanho ?? 0) / 1024, 0, ',', '.') }} KB), SHA-256 <span class="hash">{{ $a['doc']->arquivo_hash }}</span>. O arquivo original está no processo eletrônico e acompanha a divulgação.</div>
        @else
            @php($def = \App\Licitacao\TiposDocumento::get($a['tipo']))
            <div class="meta">{{ $def['base'] }} · versão {{ $a['doc']->versao }}@if ($a['doc']->concluido_em) · concluída em {{ $a['doc']->concluido_em->format('d/m/Y') }}@endif</div>
            @foreach ($def['campos'] as $campo => $c)
                @php($valor = trim((string) ($a['doc']->conteudo[$campo] ?? '')))
                @if ($valor !== '')
                    @if (count($def['campos']) > 1)<h2>{{ $c['rotulo'] }}</h2>@endif
                    <div class="texto">{!! nl2br(e($valor)) !!}</div>
                @endif
            @endforeach
        @endif
        @if ($a['tipo'] === 'mapa_riscos' && $riscos->isNotEmpty())
            <h2>Matriz de riscos (art. 22 e art. 103)</h2>
            <table>
                <thead><tr><th style="width:5%">Nº</th><th>Risco</th><th style="width:12%">P × I</th><th style="width:14%">Alocação</th><th style="width:32%">Tratamento</th></tr></thead>
                <tbody>
                    @foreach ($riscos as $r)
                        <tr><td>{{ $loop->iteration }}</td><td>{{ $r->risco }}</td><td>{{ $r->probabilidade }} × {{ $r->impacto }}</td><td>{{ ucfirst($r->alocacao) }}</td><td>{{ $r->tratamento ?? '—' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endforeach
</body>
</html>
