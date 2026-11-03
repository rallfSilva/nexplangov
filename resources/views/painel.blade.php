@php($sit = fn ($s) => $porSituacao[$s]->total ?? 0)
<x-layouts.app titulo="Painel">
    <div class="row">
        <div>
            <h1 class="h1">Olá, {{ \Illuminate\Support\Str::of(auth()->user()->name)->before(' ') }}</h1>
            <p class="sub">{{ now()->locale('pt_BR')->translatedFormat('l, d \d\e F \d\e Y') }} · contratações de {{ $ano }} do {{ auth()->user()->orgao?->sigla ?? 'sistema' }}</p>
        </div>
        <div class="btns">
            @can('demandas.criar')<a href="{{ route('demandas.create') }}" class="btn btn-s"><x-icone nome="mais" />Nova demanda</a>@endcan
            @can('processos.gerenciar')<a href="{{ route('processos.create') }}" class="btn btn-p"><x-icone nome="mais" />Novo processo</a>@endcan
        </div>
    </div>

    <div class="kpis">
        <a href="{{ $pca ? route('pca.show', $pca) : route('pca.index') }}" class="card kpi card-link" style="text-decoration:none;color:inherit">
            <span class="lbl"><span class="chip c-navy"><x-icone nome="calendario" /></span>PCA {{ $ano }}</span>
            <span class="val valor">{{ $pca ? reais($pca->itens_sum_valor_total_estimado) : '—' }}</span>
            <span class="foot">{{ $pca ? $pca->itens_count.' itens · '.\App\Models\PlanoContratacao::SITUACOES[$pca->situacao] : 'Plano ainda não criado' }}</span>
        </a>
        <a href="{{ route('processos.index') }}" class="card kpi card-link" style="text-decoration:none;color:inherit">
            <span class="lbl"><span class="chip c-teal"><x-icone nome="processo" /></span>Processos em andamento</span>
            <span class="val">{{ $emAndamento }}</span>
            <span class="foot">{{ $sit('homologado') + $sit('contratado') }} já homologado(s) ou contratado(s) no ano</span>
        </a>
        <a href="{{ route('contratos.index') }}" class="card kpi card-link" style="text-decoration:none;color:inherit">
            <span class="lbl"><span class="chip c-navy"><x-icone nome="contrato" /></span>Contratado em {{ $ano }}</span>
            <span class="val valor">{{ reais($contratado) }}</span>
            <span class="foot">valor global dos contratos do ano</span>
        </a>
        <a href="{{ route('pncp.index', ['estado' => 'erro']) }}" class="card kpi card-link" style="text-decoration:none;color:inherit">
            <span class="lbl"><span class="chip {{ $pncpErros ? 'c-warn' : 'c-teal' }}"><x-icone nome="pncp" /></span>PNCP com erro</span>
            <span class="val">{{ $pncpErros }}</span>
            <span class="foot">{{ $pncpErros ? 'envios para corrigir' : 'nada pendente' }}</span>
        </a>
    </div>

    <div class="card">
        <h2>Processos do ano por fase</h2>
        @php($fases = ['planejamento', 'analise_juridica', 'aguardando_autorizacao', 'publicado', 'em_julgamento', 'homologado', 'contratado'])
        @php($max = max(1, collect($fases)->map($sit)->max()))
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:14px;margin-top:16px">
            @foreach ($fases as $f)
                <div><div class="small muted" style="font-weight:600">{{ \App\Licitacao\Enums\SituacaoProcesso::from($f)->rotulo() }}</div><div style="font-size:24px;font-weight:800">{{ $sit($f) }}</div><div class="barra"><span style="width:{{ $sit($f) / $max * 100 }}%"></span></div></div>
            @endforeach
        </div>
    </div>

    @if (! empty($prazos))
        <section class="card">
            <div class="row"><div><h2>Prazos que pedem atenção</h2><p class="hint">Também chegam por e-mail, uma vez por marco.</p></div></div>
            <div class="list">
                @foreach ($prazos as $a)
                    <div class="li"><span class="chip {{ $a['urgente'] ? 'c-warn' : 'c-navy' }}"><x-icone nome="relogio" /></span><div style="min-width:0"><a href="{{ $a['url'] }}" class="sigla">{{ $a['titulo'] }}</a><div class="meta">{{ $a['texto'] }}</div></div></div>
                @endforeach
            </div>
        </section>
    @endif

    <div class="g2">
        <section class="card">
            <div class="row"><div><h2>Contratos vencendo</h2><p class="hint">Vigência nos próximos 120 dias.</p></div><a href="{{ route('contratos.index', ['filtro' => 'vencendo']) }}" class="btn btn-s btn-sm">Ver todos</a></div>
            <div class="list">
                @forelse ($vencendo as $c)
                    @php($d = $c->diasParaVencer())
                    <div class="li"><span class="chip {{ $d <= 30 ? 'c-warn' : 'c-navy' }}"><x-icone nome="relogio" /></span><div style="min-width:0"><a href="{{ route('contratos.show', $c) }}" class="sigla">{{ $c->identificacao() }}</a> {{ \Illuminate\Support\Str::limit($c->objeto, 50) }}<div class="meta">{{ $c->fornecedor->razao_social }}</div></div><span class="r small" style="font-weight:700">{{ $d < 0 ? 'vencido' : $d.' dias' }}</span></div>
                @empty
                    <div class="li"><span class="chip c-teal"><x-icone nome="ok" /></span><div>Nenhum contrato vence nos próximos 120 dias.</div></div>
                @endforelse
            </div>
        </section>
        <section class="card">
            <h2>Pendências</h2>
            <div class="list">
                @if ($demandasNovas !== null)<a class="li" href="{{ route('demandas.index', ['situacao' => 'enviada']) }}" style="text-decoration:none;color:inherit"><span class="chip c-navy"><x-icone nome="demanda" /></span><div>Demandas para analisar<div class="meta">enviadas pelas áreas</div></div><b class="r">{{ $demandasNovas }}</b></a>@endif
                @if ($lgpdAbertas !== null)<a class="li" href="{{ route('lgpd.index') }}" style="text-decoration:none;color:inherit"><span class="chip c-teal"><x-icone nome="privacidade" /></span><div>Pedidos LGPD em aberto<div class="meta">prazo de 15 dias</div></div><b class="r">{{ $lgpdAbertas }}</b></a>@endif
                @foreach ($meusProcessos as $p)
                    <a class="li" href="{{ route('processos.show', $p) }}" style="text-decoration:none;color:inherit"><span class="chip c-navy"><x-icone nome="processo" /></span><div style="min-width:0">{{ $p->identificacao() }} · {{ \Illuminate\Support\Str::limit($p->objeto, 46) }}<div class="meta">sob sua responsabilidade</div></div><span class="r b {{ $p->situacaoEnum()->badge() }}">{{ $p->situacaoEnum()->rotulo() }}</span></a>
                @endforeach
                @if ($demandasNovas === null && $lgpdAbertas === null && $meusProcessos->isEmpty())<div class="li muted">Nada pendente com você.</div>@endif
            </div>
        </section>
    </div>

    @can('auditoria.ver')
        <section class="card">
            <div class="row"><div><h2>Atividade recente</h2><p class="hint">Últimos registros da trilha de auditoria.</p></div><a href="{{ route('auditoria.index') }}" class="btn btn-s btn-sm">Ver tudo</a></div>
            <div class="list">
                @forelse ($ultimosEventos as $registro)
                    <div class="li"><span class="avatar" style="width:34px;height:34px;font-size:12px" aria-hidden="true">{{ $registro->usuario?->iniciais() ?? 'S' }}</span><div style="min-width:0"><div>{{ $registro->descricao }}</div><div class="meta">{{ $registro->usuario?->name ?? 'Sistema' }} · {{ $registro->created_at->diffForHumans() }}</div></div></div>
                @empty
                    <div class="li muted">Nenhum registro ainda.</div>
                @endforelse
            </div>
        </section>
    @endcan
</x-layouts.app>
