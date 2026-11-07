<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Autos do processo {{ $p->identificacao() }}</title>
@include('ocorrencias._estilo')
<style>
    .doc { page-break-before: always; }
    .meta { color: #4a5b68; font-size: 8.5pt; margin-bottom: 10px; }
    h2 { font-size: 11pt; color: #1E5470; margin: 14px 0 4px; }
    .hash { font-family: DejaVu Sans Mono, monospace; font-size: 7.5pt; word-break: break-all; }
    .termo { border: 1px solid #c9d6de; padding: 12px 14px; margin-top: 12px; }
    img.juntada { max-width: 100%; max-height: 21cm; }
    .desent { color: #8a1c1c; }
</style>
</head>
<body>
<div class="cab"><b>{{ $p->orgao?->nome }}</b><br>CNPJ {{ $p->orgao?->cnpj }}</div>
<h1>Autos do processo {{ $p->identificacao() }}</h1>
<p><b>Objeto:</b> {{ $p->objeto }}<br>
<b>Modalidade:</b> {{ $p->modalidadeEnum()->rotulo() }} · <b>Situação:</b> {{ $p->situacaoEnum()->rotulo() }}@if ($p->numero_sei) · <b>SEI:</b> {{ $p->numero_sei }}@endif<br>
<b>Unidade de origem:</b> {{ $p->unidade?->sigla ?? '—' }} · <b>Unidade atual:</b> {{ $p->unidadeAtual?->sigla ?? '—' }}<br>
<span class="meta">Gerado em {{ now()->format('d/m/Y H:i') }}. Documentos em ordem cronológica de entrada nos autos (art. 12, I, da Lei nº 14.133/2021). Os documentos desentranhados não fazem parte deste volume.</span></p>

<h2>Índice</h2>
<table>
    <thead><tr><th style="width:6%">Nº</th><th style="width:15%">Data</th><th>Documento</th><th style="width:26%">Responsável</th></tr></thead>
    <tbody>
        @forelse ($itens as $d)
            <tr><td>{{ $d['numero'] }}</td><td>{{ $d['data']->format('d/m/Y H:i') }}</td><td>{{ $d['titulo'] }}@if ($d['base'])<br><span class="meta">{{ $d['base'] }}</span>@endif</td><td>{{ $d['autor'] ?? '—' }}</td></tr>
        @empty
            <tr><td colspan="4">Nenhum documento concluído ou juntado ainda.</td></tr>
        @endforelse
    </tbody>
</table>

@foreach ($itens as $d)
    <div class="doc">
        <h1>{{ $d['numero'] }}. {{ $d['titulo'] }}</h1>
        <div class="meta">{{ $d['base'] }} · {{ $d['data']->format('d/m/Y H:i') }}@if ($d['autor']) · {{ $d['autor'] }}@endif @if ($d['detalhe']) · {{ $d['detalhe'] }}@endif</div>

        @if ($d['tipo'] === 'peca')
            @php($def = \App\Licitacao\TiposDocumento::get($d['ref']->tipo))
            @if ($d['imagem'])
                <img class="juntada" src="{{ $d['imagem'] }}" alt="{{ $d['titulo'] }}">
            @elseif ($d['ref']->origem === 'arquivo')
                <div class="termo"><b>Peça enviada como arquivo.</b> Arquivo {{ $d['ref']->arquivo_nome }} ({{ number_format(($d['ref']->arquivo_tamanho ?? 0) / 1024, 0, ',', '.') }} KB), SHA-256 <span class="hash">{{ $d['ref']->arquivo_hash }}</span>. O arquivo original acompanha o processo eletrônico no sistema.</div>
            @else
                @foreach (($def['campos'] ?? []) as $campo => $c)
                    <h2>{{ $c['rotulo'] }}</h2>
                    <div>{!! nl2br(e(trim((string) ($d['ref']->conteudo[$campo] ?? '')) ?: 'Não preenchido.')) !!}</div>
                @endforeach
            @endif
            @if ($d['ref']->assinaturas->isNotEmpty())
                <h2>Assinaturas eletrônicas</h2>
                <table><thead><tr><th>Nome</th><th>Qualificação</th><th>Data</th><th>Código</th></tr></thead><tbody>
                    @foreach ($d['ref']->assinaturas->sortBy('assinado_em') as $a)
                        <tr><td>{{ $a->nome }}</td><td>{{ $a->perfil }}</td><td>{{ $a->assinado_em->format('d/m/Y H:i') }}</td><td>{{ \App\Licitacao\Fluxo\Assinaturas::formatarCodigo($a->codigo) }}</td></tr>
                    @endforeach
                </tbody></table>
                <div class="meta">Confira em {{ route('assinaturas.validar') }}</div>
            @endif
            <div class="meta">Impressão digital da peça (SHA-256): <span class="hash">{{ $d['ref']->hashAtual() }}</span></div>
        @elseif ($d['tipo'] === 'contrato')
            <table>
                <tr><th style="width:30%">Contratada</th><td>{{ $d['ref']->fornecedor?->razao_social }}</td></tr>
                <tr><th>Objeto</th><td>{{ $d['ref']->objeto }}</td></tr>
                <tr><th>Valor global</th><td>{{ reais($d['ref']->valor_global) }}</td></tr>
                <tr><th>Vigência</th><td>{{ $d['ref']->vigencia_inicio?->format('d/m/Y') }} a {{ $d['ref']->vigencia_fim?->format('d/m/Y') }}</td></tr>
                @if ($d['ref']->numero_controle_pncp)<tr><th>PNCP</th><td>{{ $d['ref']->numero_controle_pncp }}</td></tr>@endif
            </table>
            @if ($d['ref']->arquivo_hash)<div class="meta">Instrumento assinado: {{ $d['ref']->arquivo_nome }}, SHA-256 <span class="hash">{{ $d['ref']->arquivo_hash }}</span></div>@endif
        @elseif ($d['tipo'] === 'ata')
            <div class="termo">Ata da sessão pública lavrada em {{ $p->sessao_encerrada_em?->format('d/m/Y H:i') }}. SHA-256 <span class="hash">{{ $p->ata_sessao_hash }}</span>. O PDF original acompanha o processo eletrônico no sistema.</div>
        @else
            @if ($d['ref']->descricao)<p>{{ $d['ref']->descricao }}</p>@endif
            @if ($d['imagem'])
                <img class="juntada" src="{{ $d['imagem'] }}" alt="{{ $d['titulo'] }}">
            @endif
            <div class="termo">
                <b>Termo de juntada.</b> Nesta data foi juntado aos autos o documento "{{ $d['ref']->titulo }}" ({{ \App\Models\JuntadaProcesso::TIPOS[$d['ref']->tipo] ?? $d['ref']->tipo }}), arquivo {{ $d['ref']->arquivo_nome }}, {{ number_format($d['ref']->arquivo_tamanho / 1024, 0, ',', '.') }} KB @if ($d['ref']->paginas), {{ $d['ref']->paginas }} página(s)@endif, por {{ $d['autor'] }}.
                SHA-256 <span class="hash">{{ $d['ref']->arquivo_hash }}</span>.
                @unless ($d['imagem'])O arquivo original acompanha o processo eletrônico no sistema e pode ser conferido pela impressão digital acima.@endunless
            </div>
        @endif
    </div>
@endforeach
</body>
</html>
