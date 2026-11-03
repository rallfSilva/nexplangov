<x-layouts.app titulo="Pagamentos">
    @php($pag = auth()->user()->can('pagamentos.gerenciar'))
    @php($autoridade = auth()->user()->can('processos.autorizar'))
    @php($cats = \App\Licitacao\Regras\OrdemCronologica::CATEGORIAS)
    <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:space-between;align-items:flex-start">
        <div><h1 class="h1">Pagamentos na ordem cronológica</h1><p class="sub">Uma fila por fonte de recursos e categoria. Pagar fora da ordem só com justificativa prévia da autoridade. <span class="lei">art. 141</span></p></div>
        @if ($orgao)<a class="btn btn-s" href="{{ route('pagamentos.publico', $orgao) }}" target="_blank" rel="noopener">Página pública mensal <span class="lei">§ 3º</span></a>@endif
    </div>

    @if ($comunicacoes->isNotEmpty())
        <div class="flash flash-aviso" role="status"><x-icone nome="alerta" style="flex:none" /><div><b>Pagamento fora da ordem sem comunicação</b> ao controle interno e ao tribunal de contas (art. 141, § 1º):
            {{ $comunicacoes->map(fn ($l) => $l->identificacao().' ('.$l->fornecedor->razao_social.')')->join('; ') }}.</div></div>
    @endif

    @forelse ($filas as $chave => $fila)
        @php([$fonte, $cat] = explode('|', $chave, 2))
        <section class="card"><h2 style="margin:0">{{ $fonte }} · {{ $cats[$cat] ?? $cat }}</h2>
            <div class="tbl-wrap" style="margin-top:8px"><table class="tbl"><thead><tr><th>Posição</th><th>Credor</th><th>Origem</th><th>Liquidado</th><th>Vence</th><th class="num">Líquido</th><th></th></tr></thead><tbody>
                @foreach ($fila as $l)
                    <tr><td><b>{{ $loop->iteration }}º</b> <span class="muted small">{{ $l->identificacao() }}</span></td>
                        <td>{{ $l->fornecedor->razao_social }}<div class="small muted">NF {{ $l->nota_fiscal }}</div></td>
                        <td class="small">{{ $l->referencia() }}</td>
                        <td>{{ $l->liquidado_em->format('d/m/Y') }}</td>
                        <td>{{ $l->vencimento->format('d/m/Y') }}@if ($l->atrasoPermiteExtincao())<div class="small" style="color:#9F1D1D;font-weight:600">Atraso acima de 2 meses: o contratado pode pedir a extinção (art. 137, § 2º, IV)</div>@elseif ($l->atrasada())<div class="small" style="color:#9F1D1D">Atrasado</div>@endif</td>
                        <td class="num valor">{{ reais($l->valor_liquido) }}</td>
                        <td class="acoes">
                            @if ($l->quebraAutorizada())<span class="b b-info">Fora da ordem: art. 141, § 1º, {{ $l->quebra_hipotese }}</span>@endif
                            @if ($pag && ($loop->first || $l->quebraAutorizada()))
                                <form method="POST" action="{{ route('pagamentos.pagar', $l) }}" class="btns" style="margin-top:4px">@csrf
                                    <input type="date" name="pago_em" class="input" style="width:150px" value="{{ today()->toDateString() }}" aria-label="Data do pagamento de {{ $l->identificacao() }}" required>
                                    <input name="ordem_bancaria" class="input" style="width:140px" placeholder="Ordem bancária" aria-label="Ordem bancária de {{ $l->identificacao() }}" required>
                                    <button class="btn btn-p btn-sm" type="submit">Pagar</button></form>
                            @endif
                            @if ($autoridade && ! $loop->first && ! $l->quebraAutorizada())
                                <details><summary class="small" style="cursor:pointer;font-weight:600">Pagar fora da ordem</summary>
                                    <form method="POST" action="{{ route('pagamentos.quebra', $l) }}" style="margin-top:6px;min-width:280px">@csrf
                                        <label for="hip-{{ $l->id }}" class="small">Hipótese (art. 141, § 1º)</label>
                                        <select id="hip-{{ $l->id }}" name="hipotese" class="input">@foreach (\App\Licitacao\Regras\OrdemCronologica::HIPOTESES as $v => $r)<option value="{{ $v }}">{{ $v }} · {{ \Illuminate\Support\Str::limit($r, 70) }}</option>@endforeach</select>
                                        <label for="jus-{{ $l->id }}" class="small">Justificativa</label>
                                        <textarea id="jus-{{ $l->id }}" name="justificativa" class="input" rows="3" required></textarea>
                                        <button class="btn btn-s btn-sm" type="submit" style="margin-top:6px">Justificar</button></form></details>
                            @endif
                            @if ($pag)
                                <details><summary class="small muted" style="cursor:pointer">Cancelar</summary>
                                    <form method="POST" action="{{ route('pagamentos.cancelar', $l) }}" class="btns" style="margin-top:4px">@csrf<input name="motivo" class="input" style="width:200px" placeholder="Motivo" aria-label="Motivo do cancelamento de {{ $l->identificacao() }}" required><button class="btn btn-s btn-sm">Cancelar liquidação</button></form></details>
                            @endif
                        </td></tr>
                @endforeach
            </tbody></table></div>
        </section>
    @empty
        <section class="card"><p class="muted">Nenhum crédito liquidado aguardando pagamento.</p></section>
    @endforelse

    @if ($pag && $ordens->isNotEmpty())
        <section class="card"><h2>Ordens de fornecimento recebidas e ainda não liquidadas</h2>
            @foreach ($ordens as $o)
                @php($resta = round($o->recebido('definitivo') * (float) $o->valor_unitario - (float) \App\Models\Liquidacao::where('ordem_id', $o->id)->where('situacao', '!=', 'cancelado')->sum('valor_bruto'), 2))
                <details style="margin:6px 0"><summary style="cursor:pointer"><b>Ordem {{ $o->identificacao() }}</b> · {{ $o->ataItem->ata->fornecedor->razao_social }} · a liquidar {{ reais($resta) }}</summary>
                    @include('pagamentos._liquidar', ['acao' => route('ordens.liquidar', $o), 'p' => 'of'.$o->id, 'categoria' => 'fornecimento', 'valor' => $resta, 'fontes' => $fontes])</details>
            @endforeach
        </section>
    @endif

    <section class="card card-flush"><h2 style="padding:16px 16px 0">Últimos pagamentos</h2><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Crédito</th><th>Credor</th><th>Pago em</th><th>Ordem bancária</th><th class="num">Líquido</th><th>Fora da ordem</th></tr></thead><tbody>
        @forelse ($pagos as $l)
            <tr><td>{{ $l->identificacao() }}<div class="small muted">{{ $l->referencia() }}</div></td><td>{{ $l->fornecedor->razao_social }}</td><td>{{ $l->pago_em->format('d/m/Y') }}</td><td>{{ $l->ordem_bancaria }}</td><td class="num valor">{{ reais($l->valor_liquido) }}</td>
                <td>@if ($l->quebraAutorizada())<span class="small">art. 141, § 1º, {{ $l->quebra_hipotese }}</span>
                    @if ($pag && $l->comunicacaoPendente())
                        <form method="POST" action="{{ route('pagamentos.comunicacao', $l) }}" class="btns" style="margin-top:4px">@csrf
                            <select name="destino" class="input" style="width:auto" aria-label="Destino da comunicação de {{ $l->identificacao() }}">@if (! $l->comunicado_controle_em)<option value="controle">Controle interno</option>@endif @if (! $l->comunicado_tribunal_em)<option value="tribunal">Tribunal de contas</option>@endif</select>
                            <input type="date" name="data" class="input" style="width:150px" value="{{ today()->toDateString() }}" aria-label="Data da comunicação de {{ $l->identificacao() }}">
                            <button class="btn btn-s btn-sm">Registrar comunicação</button></form>
                    @elseif ($l->comunicado_controle_em)<div class="small muted">Comunicado em {{ $l->comunicado_controle_em->format('d/m/Y') }} e {{ $l->comunicado_tribunal_em?->format('d/m/Y') }}</div>@endif
                @else – @endif</td></tr>
        @empty
            <tr><td colspan="6" class="vazio">Nenhum pagamento registrado.</td></tr>
        @endforelse
    </tbody></table></div></section>
</x-layouts.app>
