<x-layouts.app titulo="Demandas">
    <div class="row">
        <div>
            <h1 class="h1">Demandas (DFD)</h1>
            <p class="sub">Pedidos das áreas requisitantes. Cada unidade lança os seus DFDs com itens do catálogo CATMAT/CATSER. Os aprovados são consolidados no PCA ou viram processo. <span class="lei">Art. 12, VII, e art. 72, I</span></p>
        </div>
        @can('demandas.criar')<a href="{{ route('demandas.create') }}" class="btn btn-p"><x-icone nome="mais" />Nova demanda</a>@endcan
    </div>

    @can('pca.gerenciar')
        <section class="card">
            <h2>Prazo de envio de demandas</h2>
            <p class="hint">Depois da data-limite, a unidade só envia demanda para o exercício nas exceções (sigilosa, suprimento de fundos, pronto pagamento), com justificativa <span class="lei">art. 12, VII</span></p>
            <form method="POST" action="{{ route('demandas.prazos') }}" class="filtros" style="margin-top:10px" novalidate>@csrf
                <div class="campo"><label for="prazo-exercicio">Exercício do PCA</label><select id="prazo-exercicio" name="exercicio" class="input">@foreach ([now()->year, now()->year + 1] as $ex)<option value="{{ $ex }}">{{ $ex }}</option>@endforeach</select></div>
                <div class="campo"><label for="prazo-data">Data-limite</label><input id="prazo-data" type="date" name="data_limite" class="input"></div>
                <div class="btns"><button class="btn btn-s btn-sm" type="submit">Definir prazo</button></div>
            </form>
            @if ($prazos->isNotEmpty())
                <p class="small" style="margin-top:8px">@foreach ($prazos as $p)PCA {{ $p->exercicio }}: até {{ $p->data_limite->format('d/m/Y') }}@if (\App\Licitacao\Regras\PrazoDemanda::encerrado($p->data_limite, today())) <span class="b b-mute">encerrado</span>@endif @if (! $loop->last) · @endif @endforeach</p>
            @endif
        </section>
    @endcan

    <form method="GET" class="card filtros">
        <div class="campo">
            <label for="f-sit">Situação</label>
            <select id="f-sit" name="situacao" class="input" onchange="this.form.submit()">
                <option value="">Todas</option>
                @foreach (\App\Models\Demanda::SITUACOES as $v => $r)<option value="{{ $v }}" @selected(request('situacao') === $v)>{{ $r }}</option>@endforeach
            </select>
        </div>
    </form>

    <div class="card card-flush">
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Nº</th><th>Objeto</th><th>Unidade</th><th class="num">Valor estimado</th><th>Situação</th><th class="acoes"><span class="sr-only">Ações</span></th></tr></thead>
                <tbody>
                    @forelse ($demandas as $d)
                        <tr>
                            <td class="sigla">{{ $d->numero }}/{{ $d->ano }}</td>
                            <td>{{ $d->objeto }}<div class="muted small">{{ $d->solicitante?->name }} · {{ $d->created_at->format('d/m/Y') }}@if ($d->exercicio) · PCA {{ $d->exercicio }}@endif @if ($d->itemPca) · item {{ $d->itemPca->numero }} do PCA @endif</div>
                                @if ($d->vinculadas->isNotEmpty())<div class="small muted">Vinculada a {{ $d->vinculadas->map(fn ($v) => $v->numero.'/'.$v->ano)->implode(', ') }}</div>@endif
                                @if ($d->excecao_prazo)<div class="small"><span class="b b-warn">fora do prazo</span> {{ \App\Licitacao\Regras\PrazoDemanda::EXCECOES[$d->excecao_prazo] ?? '' }}</div>@endif
                                @if ($d->itens->isNotEmpty())<details class="small"><summary style="cursor:pointer">{{ $d->itens->count() }} {{ $d->itens->count() === 1 ? 'item' : 'itens' }}</summary><ul style="margin:4px 0 0;padding-left:18px">@foreach ($d->itens as $i)<li>{{ $i->codigo_catalogo ? ($i->material_servico === 'S' ? 'CATSER ' : 'CATMAT ').$i->codigo_catalogo.' · ' : '' }}{{ \Illuminate\Support\Str::limit($i->descricao, 80) }} · {{ numero_br($i->quantidade, 0) }} {{ $i->unidade_fornecimento }} · {{ reais($i->valor_total_estimado) }}</li>@endforeach</ul></details>@endif
                                @if ($d->motivo_recusa)<div class="small" style="color:#9A3412">Devolvida: {{ $d->motivo_recusa }}</div>@endif</td>
                            <td>{{ $d->unidade?->sigla }}</td>
                            <td class="num valor">{{ $d->valor_estimado ? reais($d->valor_estimado) : '—' }}</td>
                            <td><span class="b {{ ['rascunho' => 'b-mute', 'enviada' => 'b-info', 'aceita' => 'b-ok', 'recusada' => 'b-warn', 'consolidada' => 'b-ok', 'atendida' => 'b-mute'][$d->situacao] ?? 'b-mute' }}">{{ \App\Models\Demanda::SITUACOES[$d->situacao] ?? $d->situacao }}</span></td>
                            <td class="acoes">
                                @if ($d->editavel() && ($d->solicitante_id === auth()->id() || auth()->user()->can('pca.gerenciar')))
                                    @can('demandas.criar')
                                        <a class="btn btn-s btn-sm" href="{{ route('demandas.edit', $d) }}"><x-icone nome="editar" tamanho="16" />Editar</a>
                                        <form method="POST" action="{{ route('demandas.enviar', $d) }}" style="display:inline">@csrf<button class="btn btn-p btn-sm" type="submit"><x-icone nome="enviar" tamanho="16" />Enviar</button></form>
                                    @endcan
                                @endif
                                @can('pca.gerenciar')
                                    @if ($d->situacao === 'enviada')
                                        <form method="POST" action="{{ route('demandas.analisar', $d) }}" style="display:inline">@csrf @method('PATCH')<input type="hidden" name="decisao" value="aceita"><button class="btn btn-s btn-sm" type="submit"><x-icone nome="ok" tamanho="16" />Aprovar</button></form>
                                        <form method="POST" action="{{ route('demandas.analisar', $d) }}" style="display:inline" onsubmit="const m = prompt('Motivo da devolução'); if (!m) return false; this.motivo_recusa.value = m;">@csrf @method('PATCH')<input type="hidden" name="decisao" value="recusada"><input type="hidden" name="motivo_recusa"><button class="btn btn-g btn-sm" type="submit">Devolver</button></form>
                                    @endif
                                @endcan
                                @can('processos.gerenciar')
                                    @if (in_array($d->situacao, ['aceita', 'consolidada'], true))<a class="btn btn-p btn-sm" href="{{ route('processos.create', ['demanda' => $d->id]) }}">Abrir processo</a>@endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="vazio"><b>Nenhuma demanda</b>As áreas registram aqui o que precisam contratar.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="paginacao">{{ $demandas->links() }}</div>
</x-layouts.app>
