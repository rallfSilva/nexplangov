@php($corSituacao = ['rascunho' => 'b-mute', 'recusada' => 'b-warn', 'enviada' => 'b-info', 'aceita' => 'b-ok', 'consolidada' => 'b-ok', 'atendida' => 'b-mute'])
<x-layouts.app :titulo="'DFDs do PCA '.$plano->ano">
    <div>
        <a href="{{ route('pca.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Planos</a>
        <h1 class="h1" style="margin-top:8px">DFDs do PCA {{ $plano->ano }}</h1>
        <p class="sub">Cada unidade lança os seus DFDs com itens do catálogo; o planejamento analisa e consolida no plano. <span class="lei">art. 12, VII</span></p>
    </div>
    @include('pca._abas')

    <div class="kpis">
        <div class="card kpi"><span class="lbl"><span class="chip c-navy"><x-icone nome="calendario" /></span>Exercício</span><span class="val">{{ $plano->ano }}</span></div>
        <div class="card kpi"><span class="lbl"><span class="chip c-teal"><x-icone nome="cifrao" /></span>Valor total do PCA</span><span class="val valor">{{ reais($resumo['valor']) }}</span></div>
        <div class="card kpi"><span class="lbl"><span class="chip c-teal"><x-icone nome="planilha" /></span>Itens no plano</span><span class="val">{{ $resumo['itens'] }}</span></div>
        <div class="card kpi"><span class="lbl"><span class="chip c-navy"><x-icone nome="demanda" /></span>DFDs aprovados</span><span class="val">{{ $resumo['dfds_aprovados'] }}</span></div>
    </div>

    <form method="GET" class="card filtros" role="search">
        <div class="campo"><label for="f-q">Objeto</label><input id="f-q" name="q" class="input" value="{{ request('q') }}" placeholder="Buscar pelo objeto"></div>
        <div class="campo"><label for="f-u">Unidade</label><select id="f-u" name="unidade" class="input"><option value="">Todas</option>@foreach ($unidades as $u)<option value="{{ $u->id }}" @selected((int) request('unidade') === $u->id)>{{ $u->sigla }}</option>@endforeach</select></div>
        <div class="btns"><button class="btn btn-s btn-sm" type="submit"><x-icone nome="busca" tamanho="16" />Buscar</button>
            @can('demandas.criar')<a class="btn btn-p btn-sm" href="{{ route('demandas.create', ['exercicio' => $plano->ano]) }}"><x-icone nome="mais" tamanho="16" />DFD</a>@endcan</div>
    </form>

    <div class="quadro-dfd" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;align-items:start">
        @foreach ($colunas as $chave => $col)
            <section class="card" aria-labelledby="col-{{ $chave }}" style="background:var(--color-fundo)">
                <h2 id="col-{{ $chave }}" style="margin:0 0 10px">{{ $col['titulo'] }} <span class="muted small">({{ $col['dfds']->count() }})</span></h2>
                <div style="display:flex;flex-direction:column;gap:10px">
                    @forelse ($col['dfds'] as $d)
                        <article class="card" style="padding:12px">
                            <div class="row" style="gap:8px;align-items:flex-start"><b>#{{ $d->numero }} · {{ \Illuminate\Support\Str::limit($d->objeto, 70) }}</b><span class="b {{ $corSituacao[$d->situacao] ?? 'b-mute' }}" style="margin-left:auto;white-space:nowrap">{{ \App\Models\Demanda::SITUACOES[$d->situacao] ?? $d->situacao }}</span></div>
                            <div class="small muted" style="margin-top:6px">{{ $d->unidade?->sigla ?? 'Sem unidade' }} · {{ $d->itens_count }} {{ $d->itens_count === 1 ? 'item' : 'itens' }} · {{ $d->created_at->format('d/m/Y') }}@if ($d->data_pretendida) · para {{ $d->data_pretendida->format('d/m/Y') }}@endif</div>
                            <div class="row" style="margin-top:6px"><span class="valor">{{ reais($d->valor_estimado) }}</span><span class="small muted">{{ $d->solicitante?->name }}</span></div>
                            <div class="btns" style="margin-top:8px">
                                @if ($d->editavel() && ($d->solicitante_id === auth()->id() || auth()->user()->can('pca.gerenciar')))
                                    @can('demandas.criar')<a class="btn btn-s btn-sm" href="{{ route('demandas.edit', $d) }}">Editar</a>@endcan
                                @endif
                                @can('pca.gerenciar')
                                    @if ($d->situacao === 'enviada')
                                        <form method="POST" action="{{ route('demandas.analisar', $d) }}">@csrf @method('PATCH')<input type="hidden" name="decisao" value="aceita"><button class="btn btn-s btn-sm" type="submit">Aprovar</button></form>
                                    @endif
                                @endcan
                            </div>
                        </article>
                    @empty
                        <p class="small muted">Nenhum DFD.</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>
</x-layouts.app>
