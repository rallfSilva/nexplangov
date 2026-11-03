<div class="tbl-wrap" style="margin-top:12px">
    <table class="tbl">
        <thead><tr><th>Quando</th><th>Recurso</th><th>Operação</th><th>Ambiente</th><th>Situação</th><th>Detalhe</th>@can('pncp.enviar')<th></th>@endcan</tr></thead>
        <tbody>
            @forelse ($envios as $e)
                <tr>
                    <td class="small">{{ $e->created_at->format('d/m/Y H:i') }}<div class="muted">{{ $e->usuario?->name ?? 'Sistema' }}</div></td>
                    <td>{{ \App\Models\EnvioPncp::RECURSOS[$e->recurso] ?? $e->recurso }}@if ($e->documento_titulo)<div class="muted small">{{ $e->documento_titulo }}</div>@endif</td>
                    <td>{{ ucfirst($e->operacao) }}<div class="muted small">{{ $e->metodo }} {{ $e->rota }}</div></td>
                    <td><span class="b {{ $e->ambiente === 'producao' ? 'b-ok' : 'b-info' }}">{{ \App\Models\EnvioPncp::AMBIENTES[$e->ambiente] ?? $e->ambiente }}</span></td>
                    <td><span class="b {{ $e->badge() }}">{{ \App\Models\EnvioPncp::ESTADOS[$e->estado] ?? $e->estado }}</span>@if ($e->tentativas)<div class="muted small">{{ $e->tentativas }} tentativa(s)</div>@endif</td>
                    <td class="small">{{ $e->erro ?? ($e->location ? 'Publicado: '.\App\Pncp\NumeroControle::deLocation($e->location) : '') }}@if ($e->http_status) <span class="muted">(HTTP {{ $e->http_status }})</span>@endif</td>
                    @can('pncp.enviar')<td class="acoes">
                        @if ($e->estado === 'erro')<form method="POST" action="{{ route('pncp.reenviar', $e) }}">@csrf<button class="btn btn-s btn-sm" type="submit">Reenviar</button></form>@endif
                        @if ($e->estado === 'publicado' && $e->operacao !== 'excluir' && in_array($e->recurso, ['compra', 'contrato', 'termo', 'ata', 'pca'], true))
                            <details class="excluir-pncp"><summary class="btn btn-t btn-sm">Excluir no PNCP</summary>
                                <form method="POST" action="{{ route('pncp.excluir', $e) }}" class="btns" style="margin-top:6px" onsubmit="return confirm('A exclusão fica registrada no PNCP com a justificativa. Confirma?')">@csrf
                                    <input name="justificativa" class="input" style="width:240px" minlength="10" maxlength="255" required placeholder="Justificativa (fica pública)" aria-label="Justificativa da exclusão">
                                    <button class="btn btn-s btn-sm" type="submit">Confirmar</button></form>
                            </details>
                        @endif
                    </td>@endcan
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Nenhum envio.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
