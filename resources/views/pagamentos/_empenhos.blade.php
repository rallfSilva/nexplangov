{{-- Notas de empenho do contrato ou da ata: $empenhos, $acao (URL para registrar), $p (prefixo dos ids), $pode (pagamentos.gerenciar) --}}
<div class="tbl-wrap"><table class="tbl small"><thead><tr><th>Empenho</th><th>Modalidade</th><th>Natureza · fonte</th><th class="num">Empenhado</th><th class="num">Saldo</th><th class="acoes"><span class="sr">Ações</span></th></tr></thead><tbody>
    @forelse ($empenhos as $e)
        @php($saldo = $e->saldo())
        <tr><td><b>{{ $e->identificacao() }}</b><div class="muted">{{ $e->emitido_em->format('d/m/Y') }}</div></td>
            <td>{{ \Illuminate\Support\Str::before(\App\Licitacao\Regras\NotaEmpenho::TIPOS[$e->tipo] ?? $e->tipo, ' (') }}</td>
            <td>{{ $e->natureza_despesa }}<div class="muted">{{ $e->fonte_recurso }}</div></td>
            <td class="num valor">{{ reais($e->valor) }}@if ((float) $e->valor_anulado > 0)<div class="muted">anulado {{ reais($e->valor_anulado) }}</div>@endif</td>
            <td class="num valor">{{ reais($saldo) }}@if ($e->motivo_anulacao)<div class="muted">{{ $e->motivo_anulacao }}</div>@endif</td>
            <td class="acoes">@if ($pode && $saldo > 0)
                <form method="POST" action="{{ route('empenhos.anular', $e) }}" style="display:inline-flex;gap:6px;align-items:center">@csrf
                    <label class="sr" for="{{ $p }}-anular-{{ $e->id }}">Motivo da anulação do empenho {{ $e->identificacao() }}</label><input id="{{ $p }}-anular-{{ $e->id }}" name="motivo" class="input" style="width:150px" placeholder="Motivo" minlength="10" required>
                    <button class="btn btn-s btn-sm" type="submit">Anular saldo</button></form>
            @endif</td></tr>
    @empty
        <tr><td colspan="6" class="muted">Nenhuma nota de empenho registrada. Sem empenho prévio não se liquida <span class="lei">Lei 4.320, art. 60</span>.</td></tr>
    @endforelse
</tbody></table></div>
@if ($pode)
    <details style="margin-top:8px"><summary class="btn btn-s btn-sm" style="list-style:none">+ Registrar nota de empenho</summary>
        <form method="POST" action="{{ $acao }}" class="form-grid" style="margin-top:12px">@csrf
            <div class="campo"><label for="{{ $p }}-ne-num">Número da nota de empenho</label><input id="{{ $p }}-ne-num" name="numero" class="input" maxlength="30" placeholder="Ex.: 2026NE000123" required></div>
            <div class="campo"><label for="{{ $p }}-ne-tipo">Modalidade</label><select id="{{ $p }}-ne-tipo" name="tipo" class="input">@foreach (\App\Licitacao\Regras\NotaEmpenho::TIPOS as $v => $r)<option value="{{ $v }}">{{ $r }}</option>@endforeach</select></div>
            <div class="campo"><label for="{{ $p }}-ne-data">Emitida em</label><input id="{{ $p }}-ne-data" type="date" name="emitido_em" class="input" required></div>
            <div class="campo"><label for="{{ $p }}-ne-valor">Valor empenhado</label><input id="{{ $p }}-ne-valor" name="valor" class="input" inputmode="decimal" required></div>
            <div class="campo"><label for="{{ $p }}-ne-nat">Natureza da despesa</label><input id="{{ $p }}-ne-nat" name="natureza_despesa" class="input" placeholder="Ex.: 3.3.90.39" maxlength="20" required></div>
            <div class="campo"><label for="{{ $p }}-ne-fonte">Fonte de recursos</label><input id="{{ $p }}-ne-fonte" name="fonte_recurso" class="input" placeholder="Ex.: 1500 · Recursos livres" maxlength="120" required></div>
            <div class="form-acoes full"><button class="btn btn-p btn-sm" type="submit">Registrar empenho</button></div>
        </form>
    </details>
@endif
