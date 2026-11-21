@php($cores = [1 => '#1E5470', 2 => '#34729C', 3 => '#6CB1DA', 4 => '#6EC1D1', 5 => '#0F2A3A', 6 => '#4A6273', 7 => '#1F8A70', 8 => '#C2410C', 9 => '#8BB8CF', 10 => '#9CCFD6'])
@php($maior = max(1, collect($porUnidade)->max('total') ?? 1))
@php($maiorMes = max(1, collect($calendario['meses'])->max('valor')))
@php($meses = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'])
@php($usadas = collect($porUnidade)->flatMap(fn ($u) => array_keys($u['categorias']))->unique()->sort()->values())
<x-layouts.app :titulo="'Visão geral do PCA '.$plano->ano">
    <div>
        <a href="{{ route('pca.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Planos</a>
        <h1 class="h1" style="margin-top:8px">Visão geral do PCA {{ $plano->ano }}</h1>
        <p class="sub">Onde está o valor planejado e quando as contratações devem acontecer. <span class="lei">art. 12, VII</span></p>
    </div>
    @include('pca._abas')

    @if ($calendarioPlano)
        <div class="aviso-lei" role="note"><b>{{ $calendarioPlano['encerrado'] ? 'Calendário encerrado' : 'Etapa atual' }}:</b> {{ $calendarioPlano['rotulo'] }} até {{ \Carbon\Carbon::parse($calendarioPlano['prazo'])->format('d/m/Y') }}.</div>
    @endif

    <div class="kpis">
        <div class="card kpi"><span class="lbl"><span class="chip c-navy"><x-icone nome="cifrao" /></span>Valor total do PCA</span><span class="val valor">{{ reais($resumo['valor']) }}</span></div>
        <div class="card kpi"><span class="lbl"><span class="chip c-teal"><x-icone nome="organograma" /></span>Unidades</span><span class="val">{{ count($resumo['unidades']) }}</span></div>
        <div class="card kpi"><span class="lbl"><span class="chip c-teal"><x-icone nome="planilha" /></span>Objetos</span><span class="val">{{ $objetos }}</span><span class="foot">{{ $resumo['itens'] }} itens</span></div>
        <div class="card kpi"><span class="lbl"><span class="chip c-navy"><x-icone nome="demanda" /></span>DFDs aprovados</span><span class="val">{{ $resumo['dfds_aprovados'] }}</span><span class="foot">de {{ $resumo['dfds_total'] }} enviados</span></div>
    </div>

    <section class="card">
        <h2>Valores por unidade</h2>
        <p class="hint">Valor planejado de cada unidade, dividido pela categoria dos itens.</p>
        @if ($porUnidade === [])
            <p class="small muted">O plano ainda não tem itens.</p>
        @else
            <ul class="legenda small" style="display:flex;flex-wrap:wrap;gap:12px;list-style:none;padding:0;margin:10px 0">
                @foreach ($usadas as $c)<li style="display:flex;gap:6px;align-items:center"><span aria-hidden="true" style="width:12px;height:12px;border-radius:3px;background:{{ $cores[$c] ?? '#4A6273' }}"></span>{{ $categorias[$c] ?? $c }}</li>@endforeach
            </ul>
            <div style="display:flex;flex-direction:column;gap:10px">
                @foreach ($porUnidade as $u)
                    <div style="display:grid;grid-template-columns:minmax(70px,120px) 1fr;gap:10px;align-items:center">
                        <span class="sigla small">{{ $u['unidade'] }}</span>
                        <div>
                            <div role="img" aria-label="{{ $u['unidade'] }}: {{ reais($u['total']) }}, {{ collect($u['categorias'])->map(fn ($v, $c) => ($categorias[$c] ?? $c).' '.reais($v))->implode('; ') }}" style="display:flex;height:16px;width:{{ max(2, round($u['total'] / $maior * 100, 1)) }}%;border-radius:4px;overflow:hidden">
                                @foreach ($u['categorias'] as $c => $v)<span title="{{ $categorias[$c] ?? $c }}: {{ reais($v) }}" style="flex:{{ $v }};background:{{ $cores[$c] ?? '#4A6273' }}"></span>@endforeach
                            </div>
                            <span class="small valor">{{ reais($u['total']) }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section class="card">
        <h2>Calendário de contratações</h2>
        <p class="hint">Itens pelo mês da data desejada. @if ($calendario['sem_data'])<b>{{ $calendario['sem_data'] }}</b> {{ $calendario['sem_data'] === 1 ? 'item está' : 'itens estão' }} sem data no exercício.@endif</p>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px;margin-top:10px">
            @foreach ($calendario['meses'] as $m => $c)
                @php($forca = $c['valor'] > 0 ? 0.25 + 0.75 * $c['valor'] / $maiorMes : 0)
                <div class="card" style="padding:10px;border-left:6px solid rgba(52,114,156,{{ round($forca, 2) }})">
                    <b class="small">{{ $meses[$m] }}</b>
                    <div class="valor">{{ reais($c['valor']) }}</div>
                    <div class="small muted">{{ $c['quantidade'] }} {{ $c['quantidade'] === 1 ? 'item' : 'itens' }}</div>
                </div>
            @endforeach
        </div>
    </section>
</x-layouts.app>
