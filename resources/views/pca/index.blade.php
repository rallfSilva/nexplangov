@php($rotPncp = ['publicado' => ['Publicado', 'b-ok'], 'parcial' => ['Publicado em parte', 'b-warn'], 'nao' => ['Não publicado', 'b-warn']])
<x-layouts.app titulo="Plano de contratações anual">
    <div class="row">
        <div>
            <h1 class="h1">Plano de contratações anual</h1>
            <p class="sub">Um plano por exercício, para o órgão todo ou por unidades participantes. Cada unidade lança os seus DFDs, o planejamento consolida e publica no PNCP. <span class="lei">Art. 12, VII, e § 1º</span></p>
        </div>
        @can('pca.gerenciar')<a href="{{ route('pca.create') }}" class="btn btn-p"><x-icone nome="mais" />Novo PCA</a>@endcan
    </div>

    <form method="GET" class="card filtros" role="search">
        <div class="campo"><label for="f-q">Exercício</label><input id="f-q" name="q" class="input" inputmode="numeric" value="{{ request('q') }}" placeholder="Ex.: {{ $proximoAno }}"></div>
        <div class="btns"><button class="btn btn-s btn-sm" type="submit"><x-icone nome="busca" tamanho="16" />Buscar</button></div>
    </form>

    @forelse ($planos as ['plano' => $plano, 'resumo' => $r])
        <section class="card pca-exercicio" aria-labelledby="pca-{{ $plano->id }}">
            <div class="row" style="align-items:flex-start;gap:12px;flex-wrap:wrap">
                <div>
                    <h2 id="pca-{{ $plano->id }}" style="margin:0"><a href="{{ route('pca.show', $plano) }}">PCA {{ $plano->ano }}</a></h2>
                    <span class="small muted">{{ \App\Models\PlanoContratacao::ABRANGENCIAS[$plano->abrangencia] ?? '' }}@if ($plano->unidadeResponsavel) · responsável {{ $plano->unidadeResponsavel->sigla }}@endif</span>
                </div>
                <div class="btns" style="margin-left:auto">
                    <span class="b {{ $plano->situacao === 'aprovado' ? 'b-ok' : ($plano->situacao === 'em_aprovacao' ? 'b-warn' : 'b-info') }}">{{ \App\Models\PlanoContratacao::SITUACOES[$plano->situacao] }}</span>
                    <details class="menu-exportar" style="position:relative">
                        <summary class="btn btn-s btn-sm" style="list-style:none;cursor:pointer">Ações</summary>
                        <div class="card" style="position:absolute;right:0;z-index:5;min-width:220px;padding:8px;display:flex;flex-direction:column;gap:6px">
                            <a href="{{ route('pca.dfds', $plano) }}">Ver DFDs</a>
                            <a href="{{ route('pca.visao', $plano) }}">Visão geral</a>
                            <a href="{{ route('pca.versoes', $plano) }}">Histórico</a>
                            @can('pca.gerenciar')<a href="{{ route('pca.edit', $plano) }}">Editar cadastro</a>@endcan
                            <a href="{{ route('pca.consolidacao', $plano) }}">Consolidar</a>
                            @can('pncp.enviar')
                                @if ($r['pncp'] !== 'nao')<a href="{{ route('pca.sincronizar', $plano) }}">Sincronizar com o PNCP</a>@endif
                                @if ($plano->situacao === 'aprovado')
                                    <form method="POST" action="{{ route('pncp.pca', $plano) }}">@csrf<button class="btn btn-g btn-sm" type="submit" style="padding:0">{{ $r['pncp'] === 'nao' ? 'Publicar no PNCP' : 'Atualizar no PNCP' }}</button></form>
                                @endif
                            @endcan
                        </div>
                    </details>
                </div>
            </div>

            <div class="kpis" style="margin-top:12px">
                <div class="kpi"><span class="lbl"><span class="chip c-navy"><x-icone nome="cifrao" /></span>Valor estimado</span><span class="val valor">{{ reais($r['valor']) }}</span></div>
                <div class="kpi"><span class="lbl"><span class="chip c-teal"><x-icone nome="planilha" /></span>Itens</span><span class="val">{{ $r['itens'] }}</span></div>
                <div class="kpi"><span class="lbl"><span class="chip c-teal"><x-icone nome="demanda" /></span>DFDs aprovados</span><span class="val">{{ $r['dfds_aprovados'] }} de {{ $r['dfds_total'] }}</span></div>
                <div class="kpi"><span class="lbl"><span class="chip c-navy"><x-icone nome="pncp" /></span>Publicação no PNCP</span><span class="val"><span class="b {{ $rotPncp[$r['pncp']][1] }}">{{ $rotPncp[$r['pncp']][0] }}</span></span></div>
            </div>

            @if ($r['unidades'] !== [])
                <details style="margin-top:12px" @if ($loop->first) open @endif>
                    <summary style="cursor:pointer"><b>Unidades participantes</b> <span class="muted small">· {{ count($r['unidades']) }}</span></summary>
                    <div class="tbl-wrap" style="margin-top:8px">
                        <table class="tbl">
                            <thead><tr><th>Unidade</th><th class="num">Valor estimado</th><th class="num">Itens</th><th>PNCP</th><th>DFDs consolidados</th><th>Situação</th></tr></thead>
                            <tbody>
                                @foreach ($r['unidades'] as $u)
                                    <tr>
                                        <td class="sigla">{{ $u['unidade']->sigla }}<div class="small muted">{{ $u['unidade']->nome }}</div></td>
                                        <td class="num valor">{{ reais($u['valor']) }}</td>
                                        <td class="num">{{ $u['itens'] }}</td>
                                        <td><span class="b {{ $u['pncp'] === 'publicado' ? 'b-ok' : 'b-warn' }}">{{ $u['pncp'] === 'publicado' ? 'Publicado' : 'Pendente' }}</span></td>
                                        <td style="min-width:200px"><div class="row" style="gap:8px;align-items:center"><div class="barra" style="flex:1" role="progressbar" aria-valuenow="{{ $u['percentual'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="DFDs consolidados de {{ $u['unidade']->sigla }}"><span style="width:{{ $u['percentual'] }}%"></span></div><span class="small">{{ $u['consolidados'] }}/{{ $u['total'] }}</span></div></td>
                                        <td><span class="b {{ $u['total'] > 0 && $u['consolidados'] === $u['total'] ? 'b-ok' : 'b-info' }}">{{ $u['total'] > 0 && $u['consolidados'] === $u['total'] ? 'Consolidado' : 'Em elaboração' }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endif
        </section>
    @empty
        <div class="card vazio"><b>Nenhum PCA ainda</b>Cadastre o plano do próximo exercício, com as unidades participantes e o calendário de elaboração.</div>
    @endforelse
</x-layouts.app>
