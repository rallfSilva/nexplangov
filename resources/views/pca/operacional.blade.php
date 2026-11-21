<x-layouts.app :titulo="'Operacional do PCA '.$plano->ano">
    <div>
        <a href="{{ route('pca.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Planos</a>
        <h1 class="h1" style="margin-top:8px">Operacional do PCA {{ $plano->ano }}</h1>
        <p class="sub">Quanto do que se contrata no exercício estava no plano. Contratação fora do PCA precisa de justificativa. <span class="lei">art. 12, VII, e § 1º; art. 18</span></p>
    </div>
    @include('pca._abas')

    <form method="GET" class="card filtros">
        <div class="campo"><label for="f-u">Unidade requisitante</label><select id="f-u" name="unidade" class="input"><option value="">Todas</option>@foreach ($unidades as $u)<option value="{{ $u->id }}" @selected($unidade === $u->id)>{{ $u->sigla }}</option>@endforeach</select></div>
        <div class="campo"><label for="f-ate">Até</label><input id="f-ate" type="date" name="ate" class="input" value="{{ $ate->format('Y-m-d') }}"></div>
        <div class="btns"><button class="btn btn-s btn-sm" type="submit">Filtrar</button></div>
    </form>

    <div class="kpis">
        <div class="card kpi"><span class="lbl"><span class="chip c-navy"><x-icone nome="ok" /></span>Aderência ao PCA</span><span class="val">{{ numero_br($ind['aderencia']) }}%</span><span class="foot">{{ $ind['planejados'] }} de {{ $ind['total'] }} processos · até {{ $ate->format('d/m/Y') }}</span></div>
        <div class="card kpi"><span class="lbl"><span class="chip c-teal"><x-icone nome="cifrao" /></span>Valor planejado</span><span class="val valor">{{ reais($ind['valor_planejado']) }}</span><span class="foot">processos com item do PCA</span></div>
        <div class="card kpi"><span class="lbl"><span class="chip c-warn"><x-icone nome="alerta" /></span>Fora do PCA</span><span class="val valor">{{ reais($ind['fora_valor']) }}</span><span class="foot">{{ $ind['fora_quantidade'] }} {{ $ind['fora_quantidade'] === 1 ? 'processo' : 'processos' }}</span></div>
        <div class="card kpi"><span class="lbl"><span class="chip c-warn"><x-icone nome="relogio" /></span>DFDs em atraso</span><span class="val valor">{{ reais($ind['atraso_valor']) }}</span><span class="foot">{{ $ind['atraso_quantidade'] }} aprovados sem processo após a data</span></div>
    </div>

    <div class="g2">
        <section class="card">
            <h2>Aderência ao planejamento por unidade</h2>
            <p class="hint">Processos com item do PCA sobre o total de processos da unidade.</p>
            <div class="list">
                @forelse ($ind['por_unidade'] as $l)
                    <div class="li" style="flex-direction:column;align-items:stretch;gap:6px">
                        <div class="row"><span class="sigla">{{ $l['unidade'] }}</span><span class="small">{{ $l['planejados'] }} de {{ $l['total'] }} · <b>{{ numero_br($l['aderencia']) }}%</b></span></div>
                        <div class="barra" role="progressbar" aria-valuenow="{{ $l['aderencia'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Aderência de {{ $l['unidade'] }}"><span style="width:{{ $l['aderencia'] }}%"></span></div>
                    </div>
                @empty
                    <div class="li muted">Nenhum processo do exercício no período.</div>
                @endforelse
            </div>
        </section>
        <section class="card">
            <h2>Índice de planejamento tardio</h2>
            <p class="hint">Processos fora do PCA ou de DFD enviado depois do prazo, sobre o total.</p>
            @php($graus = round(3.6 * $ind['tardio_indice'], 1))
            <div style="display:flex;justify-content:center;margin:16px 0">
                <div role="img" aria-label="Índice de planejamento tardio: {{ numero_br($ind['tardio_indice']) }}%" style="width:160px;height:160px;border-radius:50%;background:conic-gradient(var(--color-alerta) 0 {{ $graus }}deg, var(--color-agua) {{ $graus }}deg 360deg);display:grid;place-items:center">
                    <div style="width:116px;height:116px;border-radius:50%;background:#fff;display:grid;place-items:center"><b style="font-size:22px">{{ numero_br($ind['tardio_indice']) }}%</b></div>
                </div>
            </div>
            <p class="small" style="text-align:center">{{ $ind['tardios'] }} de {{ $ind['total'] }} contratações · fora do PCA ou tardias: {{ $ind['tardios'] }} · planejadas a tempo: {{ $ind['total'] - $ind['tardios'] }}</p>
        </section>
    </div>

    <div class="g2">
        <section class="card">
            <h2>Contratações fora do PCA</h2>
            <div class="list">
                @forelse ($foraDoPlano as $p)
                    <a class="li" href="{{ route('processos.show', $p) }}" style="text-decoration:none;color:inherit"><div>{{ $p->numero }}/{{ $p->ano }} · {{ \Illuminate\Support\Str::limit($p->objeto, 70) }}<div class="meta">{{ $p->unidade?->sigla }} · {{ \Illuminate\Support\Str::limit($p->justificativa_fora_pca, 90) }}</div></div><b class="r valor">{{ reais($p->valor_estimado) }}</b></a>
                @empty
                    <div class="li muted">Nenhuma.</div>
                @endforelse
            </div>
        </section>
        <section class="card">
            <h2>DFDs aprovados em atraso</h2>
            <div class="list">
                @forelse ($emAtraso as $d)
                    <div class="li"><div>#{{ $d->numero }} · {{ \Illuminate\Support\Str::limit($d->objeto, 70) }}<div class="meta">{{ $d->unidade?->sigla }} · pretendido para {{ $d->data_pretendida->format('d/m/Y') }}</div></div><b class="r valor">{{ reais($d->valor_estimado) }}</b></div>
                @empty
                    <div class="li muted">Nenhum.</div>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.app>
