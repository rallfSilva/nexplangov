<x-layouts.app titulo="Governança das contratações">
    @php($pct = fn ($v) => $v === null ? 'sem dados' : $v.'%')
    <div class="row">
        <div>
            <h1 class="h1">Governança das contratações</h1>
            <p class="sub">Indicadores para a alta administração acompanhar o ciclo das contratações <span class="lei">art. 11, parágrafo único</span></p>
        </div>
        <form method="GET" class="btns"><label for="ano" class="small">Ano</label><select id="ano" name="ano" class="input" style="width:auto" onchange="this.form.submit()">@foreach (range(now()->year + 1, now()->year - 4) as $a)<option value="{{ $a }}" @selected($a === $ano)>{{ $a }}</option>@endforeach</select></form>
    </div>

    <div class="kpis">
        <div class="card kpi"><span class="lbl">Execução do PCA</span><span class="val">{{ $pct($pca['pct']) }}</span><span class="foot">{{ $pca['usados'] }} de {{ $pca['itens'] }} itens do plano já em processo <span class="lei">art. 12, VII</span></span>
            @if ($pca['pct'] !== null)<div class="barra"><span style="width:{{ $pca['pct'] }}%"></span></div>@endif</div>
        <div class="card kpi"><span class="lbl">Contratações diretas</span><span class="val">{{ $pct($competicao['pct_qtd']) }}</span><span class="foot">{{ $competicao['diretas'] }} de {{ $competicao['total'] }} processos · {{ $pct($competicao['pct_valor']) }} do valor <span class="lei">arts. 72 a 75</span></span></div>
        <div class="card kpi"><span class="lbl">Economia na disputa</span><span class="val">{{ $economia['pct'] === null ? 'sem dados' : number_format($economia['pct'], 1, ',', '.').'%' }}</span><span class="foot">{{ reais($economia['estimado']) }} estimados, {{ reais($economia['homologado']) }} homologados <span class="lei">art. 11, III</span></span></div>
        <div class="card kpi"><span class="lbl">Fase preparatória</span><span class="val">{{ $diasPreparatoria === null ? 'sem dados' : $diasPreparatoria.' dias' }}</span><span class="foot">média da abertura do processo à publicação <span class="lei">art. 18</span></span></div>
    </div>

    <div class="g2">
        <section class="card"><h2>Prazos em atraso agora</h2>
            <div class="list">
                <div class="li"><span class="chip {{ $prazos['pecas'] ? 'c-warn' : 'c-teal' }}"><x-icone nome="relogio" /></span><div>Peças da instrução com prazo vencido</div><span class="r" style="font-weight:700">{{ $prazos['pecas'] }}</span></div>
                <div class="li"><span class="chip {{ $prazos['impugnacoes'] ? 'c-warn' : 'c-teal' }}"><x-icone nome="balanca" /></span><div>Impugnações sem resposta no prazo <span class="lei">art. 164</span></div><span class="r" style="font-weight:700">{{ $prazos['impugnacoes'] }}</span></div>
                <div class="li"><span class="chip {{ $prazos['ordens'] ? 'c-warn' : 'c-teal' }}"><x-icone nome="contrato" /></span><div>Entregas atrasadas</div><span class="r" style="font-weight:700">{{ $prazos['ordens'] }}</span></div>
            </div>
        </section>
        <section class="card"><h2>Capacitação dos agentes</h2>
            <p class="hint">Quem atua nas contratações com curso registrado nos últimos {{ \App\Support\Parametros::valor('capacitacao_validade_meses') }} meses <span class="lei">art. 7º, II, e art. 173</span></p>
            <div class="kpi" style="padding:0"><span class="val">{{ $pct($capacitacao['pct']) }}</span><span class="foot">{{ $capacitacao['capacitados'] }} de {{ $capacitacao['agentes'] }} agentes</span>@if ($capacitacao['pct'] !== null)<div class="barra {{ $capacitacao['pct'] < 60 ? 'alerta' : '' }}"><span style="width:{{ $capacitacao['pct'] }}%"></span></div>@endif</div>
            @can('capacitacao.ver')<p style="margin-top:10px"><a class="btn btn-s btn-sm" href="{{ route('capacitacao.index') }}">Ver capacitação</a></p>@endcan
        </section>
    </div>

    <section class="card"><h2>Processos por modalidade em {{ $ano }}</h2>
        <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Modalidade</th><th class="num">Processos</th><th class="num">Valor estimado</th></tr></thead><tbody>
            @forelse ($porModalidade as $m)
                <tr><td>{{ \App\Licitacao\Enums\Modalidade::tryFrom((int) $m->modalidade)?->rotulo() ?? $m->modalidade }}</td><td class="num">{{ $m->total }}</td><td class="num valor">{{ reais($m->valor) }}</td></tr>
            @empty
                <tr><td colspan="3" class="muted">Nenhum processo neste ano.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>
</x-layouts.app>
