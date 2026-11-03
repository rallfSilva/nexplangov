<x-layouts.app titulo="Requisições de fornecimento">
    @php($cor = ['solicitada' => 'b-info', 'aprovada' => 'b-ok', 'atendida' => 'b-ok', 'recusada' => 'b-err', 'cancelada' => 'b-mute'])
    <div>
        <h1 class="h1">Requisições de fornecimento</h1>
        <p class="sub">A unidade pede, o gestor aprova e emite a ordem de fornecimento, que reserva o saldo da ata. Quem requisita não aprova a própria requisição <span class="lei">art. 7º, § 1º</span></p>
    </div>

    @can('requisicoes.criar')
        <section class="card">
            <h2>Nova requisição</h2>
            @if ($itens->isEmpty())
                <p class="hint">Não há itens com saldo em atas vigentes.</p>
            @else
                <form method="POST" action="{{ route('requisicoes.store') }}" class="form-grid">@csrf
                    <div class="campo full"><label for="ata_item_id">Item da ata</label>
                        <select id="ata_item_id" name="ata_item_id" class="input" required>@foreach ($itens as $ai)<option value="{{ $ai->id }}" @selected((int) old('ata_item_id') === $ai->id)>Ata {{ $ai->ata->identificacao() }} · {{ $ai->ata->fornecedor->razao_social }} · item {{ $ai->item->numero }}: {{ \Illuminate\Support\Str::limit($ai->item->descricao, 60) }} (saldo {{ numero_br($ai->saldo(), 2) }})</option>@endforeach</select></div>
                    <x-campo nome="quantidade" rotulo="Quantidade" obrigatorio />
                    <x-campo nome="unidade_id" rotulo="Unidade que vai receber"><select id="campo-unidade_id" name="unidade_id" class="input"><option value="">Selecione</option>@foreach ($unidades as $un)<option value="{{ $un->id }}">{{ $un->sigla }}</option>@endforeach</select></x-campo>
                    <div class="campo full"><label for="justificativa">Justificativa da necessidade</label><textarea id="justificativa" name="justificativa" class="input" rows="3" required>{{ old('justificativa') }}</textarea></div>
                    <div class="form-acoes full"><button class="btn btn-p" type="submit">Enviar requisição</button></div>
                </form>
            @endif
        </section>
    @endcan

    <section class="card">
        <h2>{{ $gerencia ? 'Todas as requisições' : 'Minhas requisições' }}</h2>
        <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Item</th><th class="num">Qtd</th><th>Requisitante</th><th>Situação</th><th></th></tr></thead><tbody>
            @forelse ($requisicoes as $r)
                <tr><td><b>Ata {{ $r->ataItem->ata->identificacao() }}</b> · {{ \Illuminate\Support\Str::limit($r->ataItem->item->descricao, 50) }}<div class="small muted">{{ $r->ataItem->ata->fornecedor->razao_social }} · {{ $r->unidade?->sigla }}</div><div class="small">{{ $r->justificativa }}</div></td>
                    <td class="num valor">{{ numero_br($r->quantidade, 2) }}</td><td>{{ $r->requisitante->name }}<div class="small muted">{{ $r->created_at->format('d/m/Y') }}</div></td>
                    <td><span class="b {{ $cor[$r->situacao] }}">{{ $r::SITUACOES[$r->situacao] }}</span>@if ($r->motivo_decisao)<div class="small muted">{{ $r->motivo_decisao }}</div>@endif</td>
                    <td class="acoes">
                        @if ($gerencia && $r->situacao === 'solicitada')
                            <form method="POST" action="{{ route('requisicoes.decidir', $r) }}" class="btns">@csrf<input name="motivo" class="input" style="width:150px" placeholder="Motivo (se recusar)" aria-label="Motivo"><button class="btn btn-s btn-sm" name="acao" value="aprovar">Aprovar</button><button class="btn btn-s btn-sm" name="acao" value="recusar">Recusar</button></form>
                        @elseif ($gerencia && $r->situacao === 'aprovada')
                            <form method="POST" action="{{ route('ordens.emitir', $r) }}" class="btns">@csrf<input type="date" name="prazo_entrega" class="input" style="width:auto" min="{{ today()->toDateString() }}" aria-label="Prazo de entrega" required><button class="btn btn-p btn-sm" type="submit">Emitir ordem</button></form>
                        @endif
                    </td></tr>
            @empty
                <tr><td colspan="5" class="muted">Nenhuma requisição ainda.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>
</x-layouts.app>
