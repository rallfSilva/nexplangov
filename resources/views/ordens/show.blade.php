<x-layouts.app :titulo="'Ordem '.$ordem->identificacao()">
    @php($gerencia = auth()->user()->can('contratos.gerenciar'))
    @php($aberta = in_array($ordem->situacao, ['emitida', 'parcial'], true))
    <div>
        <a href="{{ route('ordens.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Ordens</a>
        <h1 class="h1" style="margin-top:8px">Ordem {{ $ordem->identificacao() }} <span class="b b-info" style="vertical-align:middle">{{ $ordem::SITUACOES[$ordem->situacao] }}</span></h1>
        <p class="sub">{{ $ordem->ataItem->ata->fornecedor->razao_social }} · ata <a href="{{ route('atas.show', $ordem->ataItem->ata) }}">{{ $ordem->ataItem->ata->identificacao() }}</a> · entrega até {{ $ordem->prazo_entrega->format('d/m/Y') }}@if ($ordem->atrasada()) <span class="b b-err">atrasada</span>@endif</p>
        @if ($ordem->motivo_cancelamento)<p class="small">Cancelada: {{ $ordem->motivo_cancelamento }}</p>@endif
    </div>

    <div class="kpis">
        <div class="card kpi"><span class="lbl">Pedido</span><span class="val">{{ numero_br($ordem->quantidade, 2) }}</span><span class="foot">{{ $ordem->ataItem->item->unidade_medida }} a {{ reais($ordem->valor_unitario, 4) }} = {{ reais($ordem->valor_total) }}</span></div>
        <div class="card kpi"><span class="lbl">Recebido provisoriamente</span><span class="val">{{ numero_br($ordem->recebido('provisorio'), 2) }}</span></div>
        <div class="card kpi"><span class="lbl">Recebido definitivamente</span><span class="val">{{ numero_br($ordem->recebido('definitivo'), 2) }}</span></div>
    </div>

    <section class="card"><h2>Recebimentos</h2>
        <div class="list">
            @forelse ($ordem->recebimentos as $r)
                <div class="li"><span class="chip {{ $r->tipo === 'definitivo' ? 'c-teal' : 'c-navy' }}"><x-icone nome="ok" /></span><div><b>{{ $r->tipo === 'definitivo' ? 'Definitivo' : 'Provisório' }}</b>: {{ numero_br($r->quantidade, 2) }} · {{ $r->documento }}<div class="meta">{{ $r->data->format('d/m/Y') }} · {{ $recebedores[$r->recebido_por] ?? '' }}@if ($r->observacao) · {{ $r->observacao }}@endif</div></div></div>
            @empty
                <div class="li muted">Nada recebido ainda.</div>
            @endforelse
        </div>
        @if ($gerencia && $aberta)
            <form method="POST" action="{{ route('ordens.receber', $ordem) }}" class="form-grid" style="margin-top:14px">@csrf
                <x-campo nome="tipo" rotulo="Tipo" obrigatorio><select id="campo-tipo" name="tipo" class="input"><option value="provisorio">Provisório (conferência inicial)</option><option value="definitivo">Definitivo (após verificar a conformidade)</option></select></x-campo>
                <x-campo nome="quantidade" rotulo="Quantidade" obrigatorio />
                <x-campo nome="documento" rotulo="Nota fiscal ou termo" obrigatorio />
                <x-campo nome="data" rotulo="Data do recebimento" tipo="date" :valor="today()->toDateString()" obrigatorio />
                <div class="campo full"><label for="observacao">Observação</label><textarea id="observacao" name="observacao" class="input" rows="2"></textarea></div>
                <p class="hint full">O recebimento definitivo é feito por servidor diferente de quem emitiu a ordem e de quem recebeu provisoriamente <span class="lei">art. 140</span></p>
                <div class="form-acoes full"><button class="btn btn-p" type="submit">Registrar recebimento</button></div>
            </form>
        @endif
    </section>

    @if ($gerencia && $ordem->situacao === 'emitida')
        <section class="card"><h2>Cancelar ordem</h2>
            <form method="POST" action="{{ route('ordens.cancelar', $ordem) }}" class="form-grid">@csrf
                <div class="campo full"><label for="motivo">Motivo</label><textarea id="motivo" name="motivo" class="input" rows="2" required></textarea><span class="ajuda">O saldo volta para a ata por um lançamento de estorno. Nada é apagado.</span></div>
                <div class="form-acoes full"><button class="btn btn-s" type="submit" onclick="return confirm('Cancelar a ordem e devolver o saldo à ata?')">Cancelar ordem</button></div></form>
        </section>
    @endif
</x-layouts.app>
