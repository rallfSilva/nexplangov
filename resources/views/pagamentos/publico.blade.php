<x-layouts.auth titulo="Ordem cronológica de pagamentos">
    @php($cats = \App\Licitacao\Regras\OrdemCronologica::CATEGORIAS)
    <h1 class="h1">Ordem cronológica de pagamentos</h1>
    <p class="sub">{{ $orgao->nome }} · {{ ucfirst($mes->translatedFormat('F \d\e Y')) }} <span class="lei">art. 141, § 3º</span></p>
    <form method="GET" class="btns" style="margin:8px 0"><label for="mes-pub" class="small" style="font-weight:600">Mês</label><input id="mes-pub" type="month" name="mes" value="{{ $mes->format('Y-m') }}" class="input" style="width:auto"><button class="btn btn-s btn-sm">Ver</button></form>

    <h2 style="margin-top:14px">Pagos no mês</h2>
    @forelse ($pagos as $l)
        <div class="aviso-lei" style="margin:6px 0"><b>{{ $l->pago_em->format('d/m/Y') }}</b> · {{ $l->fornecedor->razao_social }} · {{ reais($l->valor_liquido) }}
            <div class="small muted">{{ $l->fonte_recurso }} · {{ $cats[$l->categoria] ?? $l->categoria }} · liquidado em {{ $l->liquidado_em->format('d/m/Y') }} · {{ $l->referencia() }}</div>
            @if ($l->quebra_hipotese)<div class="small"><b>Pago fora da ordem</b> (art. 141, § 1º, {{ $l->quebra_hipotese }}): {{ $l->quebra_justificativa }}</div>@endif</div>
    @empty
        <p class="muted small">Nenhum pagamento no mês.</p>
    @endforelse

    <h2 style="margin-top:14px">Aguardando pagamento, na ordem</h2>
    @forelse ($filas as $chave => $fila)
        @php([$fonte, $cat] = explode('|', $chave, 2))
        <h3 class="small" style="margin:10px 0 4px">{{ $fonte }} · {{ $cats[$cat] ?? $cat }}</h3>
        <ol style="margin:0 0 0 18px;padding:0">
            @foreach ($fila as $l)<li class="small">{{ $l->fornecedor->razao_social }} · {{ reais($l->valor_liquido) }} · liquidado em {{ $l->liquidado_em->format('d/m/Y') }} · vence {{ $l->vencimento->format('d/m/Y') }}</li>@endforeach
        </ol>
    @empty
        <p class="muted small">Nenhum crédito aguardando pagamento.</p>
    @endforelse
</x-layouts.auth>
