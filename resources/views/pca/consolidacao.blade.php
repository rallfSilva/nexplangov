@php($meses = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'])
@php($maiorMes = max(1, max($cronograma['meses'])))
@php($rotSituacao = ['coberto' => ['Coberto', 'b-ok'], 'insuficiente' => ['Insuficiente', 'b-err'], 'sem_dotacao' => ['Sem dotação', 'b-warn'], 'sem_natureza' => ['Sem natureza', 'b-mute']])
<x-layouts.app :titulo="'Consolidação do PCA '.$plano->ano">
    <div>
        <a href="{{ route('pca.show', $plano) }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />PCA {{ $plano->ano }}</a>
        <h1 class="h1" style="margin-top:8px">Consolidação do PCA {{ $plano->ano }}</h1>
        <p class="sub">Agrupamento de demandas, compras recorrentes e cronograma com o orçamento <span class="lei">art. 12, VII; art. 18; art. 40, V</span></p>
    </div>

    <section class="card">
        <h2>Sugestões de agrupamento</h2>
        <p class="hint">Itens do mesmo objeto ou do mesmo mercado que podem ir a uma só contratação, com itens ou lotes <span class="lei">art. 40, V, e § 2º</span>. A decisão é de quem planeja: o sistema só aponta.</p>
        <div class="list" style="margin-top:10px">
            @forelse ($sugestoes as $s)
                <div class="li"><span class="chip c-navy"><x-icone nome="liga" /></span><div><b>{{ $s['rotulo'] }}</b> · R$ {{ number_format($s['valor'], 2, ',', '.') }}
                    <div class="meta">Itens {{ implode(', ', $s['numeros']) }}@if ($s['data']) · primeiro em {{ \Carbon\Carbon::parse($s['data'])->format('d/m/Y') }}@endif</div>
                    <div class="small">{{ $s['motivo'] }}</div>
                    <div class="small muted">@foreach ($s['itens'] as $id){{ $itens[$id]->numero }}. {{ \Illuminate\Support\Str::limit($itens[$id]->descricao, 70) }} ({{ $itens[$id]->unidade?->sigla ?? 'sem unidade' }})@if (! $loop->last); @endif @endforeach</div>
                </div></div>
            @empty
                <div class="li muted">Nenhum item repetido ou do mesmo mercado no plano.</div>
            @endforelse
        </div>
    </section>

    <section class="card">
        <h2>Compras recorrentes por dispensa</h2>
        <p class="hint">Objetos comprados por dispensa de valor três ou mais vezes nos últimos 12 meses, mesmo em exercícios diferentes <span class="lei">art. 75, § 1º</span></p>
        <div class="list" style="margin-top:10px">
            @forelse ($recorrentes as $r)
                <div class="li"><span class="chip {{ $r['grave'] ? 'c-warn' : 'c-navy' }}"><x-icone nome="alerta" /></span><div><b>{{ $r['rotulo'] }}</b>@if ($r['grave']) <span class="b b-err">acima do limite</span>@endif
                    <div class="small">{{ $r['motivo'] }}</div><div class="meta">{{ implode(', ', $r['processos']) }}</div></div></div>
            @empty
                <div class="li muted">Nenhuma compra recorrente por dispensa nos últimos 12 meses.</div>
            @endforelse
        </div>
    </section>

    <section class="card">
        <h2>Cronograma mensal</h2>
        <p class="hint">Valor previsto por mês da data desejada · total R$ {{ number_format($cronograma['total'], 2, ',', '.') }}@if ($cronograma['sem_data'] > 0) · R$ {{ number_format($cronograma['sem_data'], 2, ',', '.') }} sem mês @endif</p>
        <div class="tbl-wrap" style="margin-top:10px"><table class="tbl"><caption class="sr">Valor planejado por mês</caption><thead><tr><th>Mês</th><th>Planejado</th><th style="width:50%"><span class="sr">Proporção</span></th></tr></thead><tbody>
            @foreach ($cronograma['meses'] as $m => $v)
                <tr><td>{{ $meses[$m] }}/{{ $plano->ano }}</td><td class="valor">R$ {{ number_format($v, 2, ',', '.') }}</td><td><div style="height:10px;border-radius:6px;background:#D1ECFF"><div style="height:10px;border-radius:6px;background:#34729C;width:{{ round($v / $maiorMes * 100) }}%"></div></div></td></tr>
            @endforeach
        </tbody></table></div>
    </section>

    <section class="card">
        <h2>Orçamento por natureza de despesa</h2>
        <p class="hint">Dotação autorizada na lei orçamentária de {{ $plano->ano }} contra o planejado <span class="lei">art. 18, caput, e art. 150</span></p>
        @foreach ($cronograma['alertas'] as $a)<div class="aviso-lei" style="margin-top:8px">{{ $a }}</div>@endforeach
        <div class="tbl-wrap" style="margin-top:10px"><table class="tbl"><thead><tr><th>Natureza</th><th>Planejado</th><th>Dotação</th><th>Saldo</th><th>Situação</th><th class="acoes"><span class="sr">Ações</span></th></tr></thead><tbody>
            @foreach ($cronograma['naturezas'] as $n)
                @php($dot = $dotacoes->firstWhere('natureza_despesa', $n['natureza']))
                <tr><td><b>{{ $n['natureza'] }}</b>@if ($dot?->descricao)<div class="small muted">{{ $dot->descricao }}</div>@endif</td><td class="valor">R$ {{ number_format($n['planejado'], 2, ',', '.') }}</td>
                    <td class="valor">{{ $n['dotado'] === null ? '—' : 'R$ '.number_format($n['dotado'], 2, ',', '.') }}</td><td class="valor">{{ $n['saldo'] === null ? '—' : 'R$ '.number_format($n['saldo'], 2, ',', '.') }}</td>
                    <td><span class="b {{ $rotSituacao[$n['situacao']][1] }}">{{ $rotSituacao[$n['situacao']][0] }}</span></td>
                    <td class="acoes">@if ($dot)@can('pca.gerenciar')<form method="POST" action="{{ route('pca.dotacoes.destroy', [$plano, $dot]) }}">@csrf @method('DELETE')<button class="btn btn-g btn-sm" type="submit">Excluir dotação</button></form>@endcan @endif</td></tr>
            @endforeach
        </tbody></table></div>
        @can('pca.gerenciar')
            <form method="POST" action="{{ route('pca.dotacoes.store', $plano) }}" class="form-grid" style="margin-top:14px">
                @csrf
                <x-campo nome="natureza_despesa" rotulo="Natureza da despesa" obrigatorio maxlength="40" ajuda="Ex.: 3.3.90.30" />
                <x-campo nome="valor_autorizado" rotulo="Dotação autorizada (R$)" obrigatorio inputmode="decimal" />
                <x-campo nome="descricao" rotulo="Ficha ou programa de trabalho" maxlength="200" />
                <div class="btns"><button class="btn btn-p" type="submit">Salvar dotação</button></div>
            </form>
        @endcan
    </section>
</x-layouts.app>
