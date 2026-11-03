<x-layouts.app titulo="Ordens de fornecimento">
    @php($cor = ['emitida' => 'b-info', 'parcial' => 'b-warn', 'entregue' => 'b-ok', 'cancelada' => 'b-mute'])
    <div>
        <h1 class="h1">Ordens de fornecimento</h1>
        <p class="sub">Cada ordem reserva saldo da ata. O recebimento é provisório e depois definitivo <span class="lei">art. 140</span></p>
    </div>
    <section class="card"><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Ordem</th><th>Item</th><th class="num">Qtd</th><th class="num">Valor</th><th>Prazo</th><th>Situação</th></tr></thead><tbody>
        @forelse ($ordens as $o)
            <tr><td><a href="{{ route('ordens.show', $o) }}" class="sigla">{{ $o->identificacao() }}</a></td>
                <td>{{ \Illuminate\Support\Str::limit($o->ataItem->item->descricao, 50) }}<div class="small muted">{{ $o->ataItem->ata->fornecedor->razao_social }}</div></td>
                <td class="num valor">{{ numero_br($o->quantidade, 2) }}</td><td class="num valor">{{ reais($o->valor_total) }}</td>
                <td>{{ $o->prazo_entrega->format('d/m/Y') }}@if ($o->atrasada()) <span class="b b-err">atrasada</span>@endif</td>
                <td><span class="b {{ $cor[$o->situacao] }}">{{ $o::SITUACOES[$o->situacao] }}</span></td></tr>
        @empty
            <tr><td colspan="6" class="muted">Nenhuma ordem emitida.</td></tr>
        @endforelse
    </tbody></table></div></section>
</x-layouts.app>
