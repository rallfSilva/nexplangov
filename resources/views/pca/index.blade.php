<x-layouts.app titulo="Plano de contratações anual">
    <div class="row">
        <div>
            <h1 class="h1">Plano de contratações anual</h1>
            <p class="sub">Tudo o que o órgão pretende contratar no exercício seguinte, com valor estimado e prioridade. <span class="lei">Art. 12, VII</span></p>
        </div>
        @can('pca.gerenciar')
            <form method="POST" action="{{ route('pca.store') }}" class="btns">
                @csrf
                <input type="hidden" name="ano" value="{{ $proximoAno }}">
                <button class="btn btn-p" type="submit"><x-icone nome="mais" />Criar PCA {{ $proximoAno }}</button>
            </form>
        @endcan
    </div>

    <div class="grid3">
        @forelse ($planos as $plano)
            <a href="{{ route('pca.show', $plano) }}" class="card card-link" style="text-decoration:none;color:inherit">
                <div class="row"><h2>PCA {{ $plano->ano }}</h2><span class="b {{ $plano->situacao === 'aprovado' ? 'b-ok' : ($plano->situacao === 'em_aprovacao' ? 'b-warn' : 'b-info') }}">{{ \App\Models\PlanoContratacao::SITUACOES[$plano->situacao] }}</span></div>
                <div class="kpi" style="margin-top:14px">
                    <span class="val valor" style="font-size:26px">{{ reais($plano->itens_sum_valor_total_estimado) }}</span>
                    <span class="foot">{{ $plano->itens_count }} {{ $plano->itens_count === 1 ? 'item' : 'itens' }} planejados</span>
                </div>
            </a>
        @empty
            <div class="card vazio" style="grid-column:1/-1"><b>Nenhum PCA ainda</b>Crie o plano do próximo exercício e inclua os itens a partir das demandas das áreas.</div>
        @endforelse
    </div>
</x-layouts.app>
