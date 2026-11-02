<x-layouts.app titulo="Publicações no PNCP">
    <div>
        <h1 class="h1">Publicações no PNCP</h1>
        <p class="sub">Fila de envios ao Portal Nacional de Contratações Públicas. Nada aparece como publicado sem a confirmação do PNCP. <span class="lei">Art. 174</span></p>
    </div>
    <div class="kpis">
        @foreach (\App\Models\EnvioPncp::ESTADOS as $estado => $rotulo)
            <a href="{{ route('pncp.index', ['estado' => $estado]) }}" class="card kpi card-link" style="text-decoration:none;color:inherit"><span class="lbl">{{ $rotulo }}</span><span class="val">{{ $contagem[$estado] ?? 0 }}</span></a>
        @endforeach
    </div>
    <div class="card">
        @if (request('estado'))<a href="{{ route('pncp.index') }}" class="btn btn-s btn-sm">Ver todos</a>@endif
        @include('pncp._lista', ['envios' => $envios])
    </div>
    <div class="paginacao">{{ $envios->links() }}</div>
</x-layouts.app>
