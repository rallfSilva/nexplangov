<x-layouts.auth titulo="Acompanhar pedido de adesão">
    <a href="{{ route('carona.index') }}" class="small" style="font-weight:600">← Atas vigentes</a>
    <h1 class="h1" style="margin-top:6px">Acompanhar pedido de adesão</h1>
    <p class="sub">Informe o protocolo e o CNPJ do órgão que pediu.</p>
    <x-flash />
    <form method="POST" action="{{ route('carona.consultar') }}" class="form-grid">@csrf
        <x-campo nome="protocolo" rotulo="Protocolo" obrigatorio :valor="session('protocolo')" placeholder="ADS-2026-000001" />
        <x-campo nome="cnpj" rotulo="CNPJ do órgão aderente" obrigatorio inputmode="numeric" />
        <div class="form-acoes full"><button class="btn btn-p" type="submit">Consultar</button></div>
    </form>

    @if ($adesao)
        <h2 style="margin-top:18px">Pedido {{ $adesao->protocolo }}</h2>
        @include('carona._resumo', ['a' => $adesao])
        @if ($adesao->situacao === 'autorizada' && $adesao->codigo_verificacao)
            <p style="margin-top:10px"><a class="btn btn-p" href="{{ route('carona.autorizacao', $adesao->codigo_verificacao) }}">Baixar o ofício de autorização (PDF)</a></p>
        @elseif ($adesao->situacao === 'solicitada')
            <p class="small muted">Próximo passo: {{ $adesao->fornecedor_aceite ? 'decisão do órgão gerenciador.' : 'o fornecedor responder se aceita fornecer ao seu órgão.' }}</p>
        @endif
    @endif
</x-layouts.auth>
