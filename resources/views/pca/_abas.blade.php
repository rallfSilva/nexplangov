{{-- Navegação entre as telas do PCA de um exercício. --}}
@php($abas = ['pca.show' => 'Itens do plano', 'pca.visao' => 'Visão geral', 'pca.dfds' => 'DFDs', 'pca.operacional' => 'Operacional', 'pca.consolidacao' => 'Consolidação'])
<nav class="abas-pca btns" aria-label="Telas do PCA {{ $plano->ano }}" style="flex-wrap:wrap">
    @foreach ($abas as $rota => $rotulo)
        <a href="{{ route($rota, $plano) }}" class="btn btn-sm {{ request()->routeIs($rota) ? 'btn-p' : 'btn-s' }}" @if (request()->routeIs($rota)) aria-current="page" @endif>{{ $rotulo }}</a>
    @endforeach
</nav>
