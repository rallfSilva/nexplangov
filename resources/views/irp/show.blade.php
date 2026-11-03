<x-layouts.app :titulo="'IRP '.$irp->identificacao()">
    @php($gerencia = auth()->user()->can('irp.gerenciar'))
    <div>
        <a href="{{ route('irp.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />IRP</a>
        <h1 class="h1" style="margin-top:8px">IRP {{ $irp->identificacao() }}</h1>
        <p class="sub">{{ $irp->objeto }} · manifestações até {{ $irp->manifestacao_ate->format('d/m/Y') }} · <span class="b b-info">{{ \App\Models\Irp::SITUACOES[$irp->situacao] }}</span> @if ($irp->processo)· processo <a href="{{ route('processos.show', $irp->processo) }}">{{ $irp->processo->identificacao() }}</a>@endif</p>
        @if ($irp->justificativa)<p class="small">{{ $irp->justificativa }}</p>@endif
    </div>

    <div class="card card-flush"><div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Participante</th><th>Item</th><th class="num">Quantidade</th><th>Situação</th><th></th></tr></thead>
        <tbody>
            @forelse ($irp->manifestacoes as $m)
                <tr><td>{{ $m->participante }}</td><td>{{ $m->descricao }}@if ($m->justificativa)<div class="small muted">{{ $m->justificativa }}</div>@endif</td>
                    <td class="num valor">{{ number_format((float) $m->quantidade, 2, ',', '.') }} {{ $m->unidade_medida }}</td>
                    <td><span class="b {{ $m->situacao === 'aceita' ? 'b-ok' : ($m->situacao === 'recusada' ? 'b-err' : 'b-info') }}">{{ ['enviada' => 'Enviada', 'aceita' => 'Aceita', 'recusada' => 'Recusada'][$m->situacao] }}</span>@if ($m->motivo_recusa)<div class="small muted">{{ $m->motivo_recusa }}</div>@endif</td>
                    <td class="acoes">@if ($gerencia && ! in_array($irp->situacao, ['consolidada', 'cancelada']))
                        <form method="POST" action="{{ route('irp.decidir', [$irp, $m]) }}" class="btns">@csrf<input name="motivo" class="input" style="width:170px" placeholder="Motivo (se recusar)"><button class="btn btn-s btn-sm" name="acao" value="aceitar">Aceitar</button><button class="btn btn-s btn-sm" name="acao" value="recusar">Recusar</button></form>@endif</td></tr>
            @empty
                <tr><td colspan="5" class="vazio"><b>Nenhuma manifestação ainda.</b></td></tr>
            @endforelse
        </tbody>
    </table></div></div>

    @can('irp.manifestar')
        @if ($irp->aceitaManifestacao())
            <section class="card"><h2>Manifestar necessidade</h2>
                <form method="POST" action="{{ route('irp.manifestar', $irp) }}" class="form-grid">@csrf
                    <x-campo nome="participante" rotulo="Participante (unidade ou órgão)" :valor="auth()->user()->orgao?->sigla" obrigatorio />
                    <div class="campo"><label for="{{ $_rot = 'c'.\Illuminate\Support\Str::random(8) }}">Unidade</label><select id="{{ $_rot }}" name="unidade_id" class="input"><option value="">—</option>@foreach ($unidades as $u)<option value="{{ $u->id }}">{{ $u->sigla }}</option>@endforeach</select></div>
                    <x-campo nome="descricao" rotulo="Descrição do item" obrigatorio /><x-campo nome="unidade_medida" rotulo="Unidade de medida" obrigatorio /><x-campo nome="quantidade" rotulo="Quantidade" obrigatorio />
                    <div class="campo full"><label for="{{ $_rot = 'c'.\Illuminate\Support\Str::random(8) }}">Justificativa</label><textarea id="{{ $_rot }}" name="justificativa" class="input" rows="2"></textarea></div>
                    <div class="form-acoes full"><button class="btn btn-p" type="submit">Enviar manifestação</button></div></form>
            </section>
        @endif
    @endcan

    @if ($gerencia && in_array($irp->situacao, ['aberta', 'encerrada']))
        <section class="card"><h2>Encerrar e consolidar</h2>
            <div class="btns">
                @if ($irp->situacao === 'aberta')<form method="POST" action="{{ route('irp.encerrar', $irp) }}">@csrf<button class="btn btn-s" type="submit">Encerrar manifestações</button></form>@endif
                <form method="POST" action="{{ route('irp.consolidar', $irp) }}" class="btns">@csrf
                    <select name="processo_id" class="input" style="width:auto;min-width:260px" aria-label="Processo" required><option value="">Processo na fase preparatória</option>@foreach ($processos as $p)<option value="{{ $p->id }}">{{ $p->identificacao() }} · {{ \Illuminate\Support\Str::limit($p->objeto, 50) }}</option>@endforeach</select>
                    <button class="btn btn-p" type="submit">Consolidar no processo</button></form>
            </div>
            <p class="hint">As manifestações aceitas viram itens do processo (mesmo item e unidade somam as quantidades) e o processo passa a ser de registro de preços.</p>
        </section>
    @endif
</x-layouts.app>
