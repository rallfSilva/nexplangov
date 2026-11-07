@php
    $dias = fn ($desde) => $desde ? max(0, (int) floor($desde->diffInDays(now()))) : null;
@endphp
<x-layouts.app titulo="Meus processos">
    <div class="row">
        <div>
            <h1 class="h1">Meus processos</h1>
            <p class="sub">Caixa de trabalho da sua unidade: o que chegou, o que está com vocês, o que está sob a sua responsabilidade e o que foi arquivado.</p>
        </div>
    </div>

    @if ($minhas === [] && ! $admin)
        <div class="flash flash-aviso" role="status"><x-icone nome="alerta" style="flex:none" /><div>Você ainda não tem unidade de lotação. Peça ao administrador do órgão para indicar a sua unidade no cadastro de usuários, para receber e enviar processos.</div></div>
    @endif

    @if ($admin || count($minhas) > 1)
        <form method="GET" class="card filtros" role="search">
            <div class="campo">
                <label for="f-unidade">Unidade</label>
                <select id="f-unidade" name="unidade" class="input">
                    <option value="">{{ $minhas ? 'Todas as minhas unidades' : 'Todas as unidades' }}</option>
                    @foreach ($unidades as $un)
                        @if ($admin || in_array($un->id, $minhas, true))<option value="{{ $un->id }}" @selected($filtro === $un->id)>{{ $un->sigla }} · {{ $un->nome }}</option>@endif
                    @endforeach
                </select>
            </div>
            <div class="btns"><button class="btn btn-s" type="submit"><x-icone nome="busca" />Filtrar</button></div>
        </form>
    @endif

    <section class="card card-flush" aria-labelledby="t-receber">
        <div style="padding:18px 20px 0"><h2 id="t-receber">A receber <span class="b {{ $aReceber->isEmpty() ? 'b-mute' : 'b-warn' }}">{{ $aReceber->count() }}</span></h2>
            <p class="hint">Processos enviados para a unidade e ainda não recebidos. O prazo dos atos continua correndo enquanto o processo está em trânsito.</p></div>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Processo</th><th>De</th><th>Para</th><th>Enviado</th><th>Despacho</th><th></th></tr></thead>
                <tbody>
                    @forelse ($aReceber as $t)
                        <tr>
                            <td><a class="sigla" href="{{ route('processos.show', [$t->processo, 'aba' => 'tramitacao']) }}">{{ $t->processo->identificacao() }}</a><div class="muted small">{{ \Illuminate\Support\Str::limit($t->processo->objeto, 70) }}</div></td>
                            <td>{{ $t->origem?->sigla ?? '—' }}</td>
                            <td>{{ $t->destino?->sigla ?? '—' }}</td>
                            <td>{{ $t->enviado_em->format('d/m/Y H:i') }}<div class="muted small">{{ $t->remetente?->name }}</div></td>
                            <td class="small">{{ \Illuminate\Support\Str::limit($t->despacho, 120) }}</td>
                            <td><form method="POST" action="{{ route('tramitacao.receber', [$t->processo, $t]) }}">@csrf<button type="submit" class="btn btn-p btn-sm">Receber</button></form></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="muted">Nada aguardando recebimento.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="card card-flush" aria-labelledby="t-unidade">
        <div style="padding:18px 20px 0"><h2 id="t-unidade">Na unidade <span class="b b-info">{{ $naUnidade->count() }}</span></h2></div>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Processo</th><th>Situação</th><th>Unidade</th><th>Responsável</th><th class="num">Dias na unidade</th></tr></thead>
                <tbody>
                    @forelse ($naUnidade as $p)
                        @php($d = $dias($chegadas[$p->id] ?? $p->created_at))
                        <tr>
                            <td><a class="sigla" href="{{ route('processos.show', $p) }}">{{ $p->identificacao() }}</a><div class="muted small">{{ \Illuminate\Support\Str::limit($p->objeto, 70) }}</div></td>
                            <td><span class="b {{ $p->situacaoEnum()->badge() }}">{{ $p->situacaoEnum()->rotulo() }}</span></td>
                            <td>{{ $p->unidadeAtual?->sigla }}</td>
                            <td>{{ $p->responsavel?->name ?? '—' }}</td>
                            <td class="num">@if ($d > 30)<span class="b b-warn">{{ $d }}</span>@else{{ $d }}@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">Nenhum processo na unidade.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="g2">
        <section class="card">
            <h2>Sob minha responsabilidade</h2>
            <div class="list">
                @forelse ($responsavel as $p)
                    <div class="li"><a href="{{ route('processos.show', $p) }}" class="sigla">{{ $p->identificacao() }}</a><span class="small muted">{{ $p->unidadeAtual?->sigla }}</span><span class="r b {{ $p->situacaoEnum()->badge() }}">{{ $p->situacaoEnum()->rotulo() }}</span></div>
                @empty
                    <p class="muted">Nenhum processo sob a sua responsabilidade.</p>
                @endforelse
            </div>
        </section>
        <section class="card">
            <h2>Enviados por mim, aguardando recebimento</h2>
            <div class="list">
                @forelse ($enviados as $t)
                    <div class="li"><a href="{{ route('processos.show', [$t->processo, 'aba' => 'tramitacao']) }}" class="sigla">{{ $t->processo->identificacao() }}</a><span class="small muted">para {{ $t->destino?->sigla }} em {{ $t->enviado_em->format('d/m/Y') }}</span></div>
                @empty
                    <p class="muted">Nenhuma remessa pendente.</p>
                @endforelse
            </div>
        </section>
    </div>

    <section class="card">
        <h2>Arquivados na unidade</h2>
        <div class="list">
            @forelse ($arquivados as $p)
                <div class="li"><a href="{{ route('processos.show', [$p, 'aba' => 'tramitacao']) }}" class="sigla">{{ $p->identificacao() }}</a><span class="small muted">{{ $p->unidadeAtual?->sigla }} · arquivado em {{ $p->arquivado_em->format('d/m/Y') }}</span><span class="r b {{ $p->situacaoEnum()->badge() }}">{{ $p->situacaoEnum()->rotulo() }}</span></div>
            @empty
                <p class="muted">Nenhum processo arquivado.</p>
            @endforelse
        </div>
    </section>
</x-layouts.app>
