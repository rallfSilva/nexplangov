<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Folha de assinaturas · {{ $def['rotulo'] }}</title>
    <style>
        body { font-family: "Plus Jakarta Sans", Arial, sans-serif; color: #1b2a35; max-width: 820px; margin: 24px auto; padding: 0 20px; }
        h1 { font-size: 20px; color: #1E5470; margin: 0 0 4px; } .sub { color: #4a5b68; font-size: 13px; margin: 0 0 20px; }
        .ass { border: 1px solid #CBEAEC; border-radius: 12px; padding: 14px 16px; margin-bottom: 14px; display: flex; gap: 16px; align-items: center; break-inside: avoid; }
        .ass svg { flex: none; } .ass b { font-size: 15px; } .ass div { font-size: 13px; line-height: 1.6; }
        .cod { font-family: ui-monospace, monospace; letter-spacing: .06em; color: #1E5470; font-weight: 700; }
        .pend { border: 1px dashed #9aa9b4; color: #6b7a86; }
        .rodape { margin-top: 28px; font-size: 12px; color: #4a5b68; border-top: 1px solid #CBEAEC; padding-top: 10px; }
        .imprimir { margin-bottom: 18px; padding: 10px 18px; border-radius: 10px; border: 0; background: #1E5470; color: #fff; font-weight: 600; cursor: pointer; }
        @media print { .imprimir { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <button class="imprimir" onclick="window.print()">Imprimir ou salvar em PDF</button>
    <h1>Folha de assinaturas eletrônicas</h1>
    <p class="sub">{{ $def['rotulo'] }} · versão {{ $doc->versao }} · processo {{ $processo->identificacao() }} · {{ $processo->orgao->nome ?? '' }}<br>Impressão digital da peça (SHA-256): <span class="cod" style="letter-spacing:0;font-size:11px">{{ $doc->hashAtual() }}</span></p>

    @foreach ($doc->assinaturas->sortBy('assinado_em') as $a)
        <div class="ass">
            {!! $assinaturas->qrCodeSvg(route('assinaturas.validar', $a->codigo), 120) !!}
            <div><b>{{ $a->nome }}</b><br>{{ $a->perfil }}<br>Assinado em {{ $a->assinado_em->format('d/m/Y \à\s H:i') }}<br>Código: <span class="cod">{{ \App\Licitacao\Fluxo\Assinaturas::formatarCodigo($a->codigo) }}</span></div>
        </div>
    @endforeach

    @foreach (\App\Models\User::whereIn('id', $ordem)->whereNotIn('id', $doc->assinaturas->pluck('user_id'))->get() as $u)
        <div class="ass pend"><div><b>{{ $u->name }}</b><br>Assinatura pendente</div></div>
    @endforeach

    <p class="rodape">Para conferir, leia o QR Code ou digite o código em <b>{{ route('assinaturas.validar') }}</b>. A página mostra quem assinou e quando, e avisa se a peça mudou depois da assinatura. Assinatura eletrônica com confirmação de senha e selo de integridade (Lei nº 14.133/2021, art. 12, VI: atos preferencialmente digitais). Gerada em {{ now()->format('d/m/Y H:i') }}.</p>
</body>
</html>
