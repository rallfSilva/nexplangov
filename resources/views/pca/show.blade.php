@php($cat = \App\Licitacao\Enums\CategoriaProcesso::opcoes())
<x-layouts.app :titulo="'PCA '.$plano->ano">
    <div class="row">
        <div>
            <a href="{{ route('pca.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Planos</a>
            <h1 class="h1" style="margin-top:8px">PCA {{ $plano->ano }} <span class="b {{ $plano->situacao === 'aprovado' ? 'b-ok' : 'b-info' }}" style="vertical-align:middle">{{ $plano->emRevisao() && $plano->situacao !== 'aprovado' ? ($plano->situacao === 'em_aprovacao' ? 'Revisão em aprovação' : 'Em revisão') : \App\Models\PlanoContratacao::SITUACOES[$plano->situacao] }}</span>@if ($plano->versao_atual > 0) <span class="b b-mute" style="vertical-align:middle">Versão {{ $plano->versao_atual }}</span>@endif</h1>
            @if ($plano->aprovado_em)<p class="sub">Aprovado por {{ $plano->aprovador?->name }} em {{ $plano->aprovado_em->format('d/m/Y H:i') }}.</p>@endif
        </div>
        <div class="btns">
            @if ($itens->isNotEmpty())
                <details class="menu-exportar" style="position:relative">
                    <summary class="btn btn-s" style="list-style:none;cursor:pointer"><x-icone nome="download" />Exportar</summary>
                    <div class="card" style="position:absolute;right:0;z-index:5;min-width:200px;padding:8px;display:flex;flex-direction:column;gap:4px">
                        <a href="{{ route('pca.exportar', [$plano, 'xlsx']) }}">Planilha (XLSX)</a>
                        <a href="{{ route('pca.exportar', [$plano, 'docx']) }}">Documento (DOCX)</a>
                        <a href="{{ route('pca.exportar', [$plano, 'csv']) }}">Dados (CSV)</a>
                    </div>
                </details>
            @endif
            @if ($plano->versao_atual > 0 || $plano->emRevisao())
                <a href="{{ route('pca.versoes', $plano) }}" class="btn btn-s"><x-icone nome="relogio" />Versões e alterações</a>
            @endif
            @can('pca.gerenciar')
                @if ($plano->emRevisao() && $plano->situacao === 'rascunho')
                    <form method="POST" action="{{ route('pca.revisao.descartar', $plano) }}" onsubmit="return confirm('Descartar a revisão? Os itens voltam a ser os da versão {{ $plano->versao_atual }}.')">@csrf<button class="btn btn-s" type="submit">Descartar revisão</button></form>
                @endif
                @if ($plano->situacao === 'rascunho')
                    <form method="POST" action="{{ route('pca.enviar', $plano) }}">@csrf<button class="btn btn-s" type="submit"><x-icone nome="enviar" />Enviar para aprovação</button></form>
                @endif
            @endcan
            @can('pca.aprovar')
                @if ($plano->situacao === 'em_aprovacao')
                    <form method="POST" action="{{ route('pca.aprovar', $plano) }}" onsubmit="return confirm('Aprovar o PCA {{ $plano->ano }}? Depois disso, mudanças só por revisão registrada.')">@csrf<button class="btn btn-p" type="submit"><x-icone nome="ok" />Aprovar PCA</button></form>
                @endif
            @endcan
            @can('pncp.enviar')
                @if ($plano->situacao === 'aprovado')
                    <form method="POST" action="{{ route('pncp.pca', $plano) }}">@csrf<button class="btn btn-s" type="submit"><x-icone nome="pncp" />{{ $plano->numero_controle_pncp ? 'Atualizar no PNCP' : 'Publicar no PNCP' }}</button></form>
                @endif
            @endcan
        </div>
    </div>

    @if ($plano->emRevisao())
        <div class="aviso-lei" role="note"><b>Revisão da versão {{ $plano->versao_atual }}</b> aberta por {{ $plano->autorRevisao?->name ?? '—' }} em {{ $plano->revisao_aberta_em->format('d/m/Y H:i') }}: {{ $plano->revisao_justificativa }}. Depois das alterações, envie para nova aprovação; a versão {{ $plano->versao_atual }} continua vigente até lá. <span class="lei">art. 12, § 1º</span></div>
    @endif
    @can('pca.gerenciar')
        @if ($plano->situacao === 'aprovado')
            <details class="card">
                <summary style="cursor:pointer"><b>Abrir revisão do plano</b> <span class="muted small">· para incluir, alterar ou excluir itens depois da aprovação</span></summary>
                <form method="POST" action="{{ route('pca.revisao.abrir', $plano) }}" class="form-grid" style="margin-top:12px">
                    @csrf
                    <x-campo nome="justificativa" rotulo="Justificativa da revisão" obrigatorio classe="full" :ajuda="'Fica registrada na nova versão e na trilha de auditoria. Mínimo de '.\App\Licitacao\Regras\VersoesPca::JUSTIFICATIVA_MINIMA.' caracteres.'"><textarea id="campo-justificativa" name="justificativa" class="input" rows="3" required>{{ old('justificativa') }}</textarea></x-campo>
                    <div class="full form-acoes"><button class="btn btn-p" type="submit"><x-icone nome="editar" />Abrir revisão</button></div>
                </form>
            </details>
        @endif
    @endcan

    <div class="kpis">
        <div class="card kpi"><span class="lbl"><span class="chip c-navy"><x-icone nome="cifrao" /></span>Valor total estimado</span><span class="val valor">{{ reais($itens->sum('valor_total_estimado')) }}</span><span class="foot">{{ $itens->count() }} itens</span></div>
        <div class="card kpi"><span class="lbl"><span class="chip c-warn"><x-icone nome="alerta" /></span>Prioridade alta</span><span class="val">{{ $itens->where('prioridade', 1)->count() }}</span><span class="foot">{{ reais($itens->where('prioridade', 1)->sum('valor_total_estimado')) }}</span></div>
        <div class="card kpi"><span class="lbl"><span class="chip c-teal"><x-icone nome="calendario" /></span>Para o 1º semestre</span><span class="val">{{ $itens->filter(fn ($i) => $i->data_desejada && $i->data_desejada->month <= 6)->count() }}</span><span class="foot">pela data desejada</span></div>
    </div>

    <div class="g2">
        <section class="card">
            <h2>Por categoria</h2>
            <p class="hint">Distribuição do valor planejado.</p>
            <div class="list">
                @php($total = max(1, $itens->sum('valor_total_estimado')))
                @forelse ($porCategoria as $categoria => $g)
                    <div class="li" style="flex-direction:column;align-items:stretch;gap:6px">
                        <div class="row"><span>{{ $cat[$categoria] ?? $categoria }} <span class="muted small">· {{ $g['qtd'] }}</span></span><b class="valor">{{ reais($g['valor']) }}</b></div>
                        <div class="barra"><span style="width:{{ round($g['valor'] / $total * 100, 1) }}%"></span></div>
                    </div>
                @empty
                    <div class="li muted">Sem itens.</div>
                @endforelse
            </div>
        </section>

        @can('pca.gerenciar')
            @if ($plano->editavel())
                <section class="card">
                    <h2>{{ $editando ? 'Editar item '.$editando->numero : 'Incluir item' }}</h2>
                    <form method="POST" action="{{ $editando ? route('pca.itens.update', [$plano, $editando]) : route('pca.itens.store', $plano) }}" style="margin-top:14px" novalidate>
                        @csrf @if ($editando) @method('PUT') @endif
                        <div class="form-grid">
                            <x-campo nome="material_servico" rotulo="Tipo" obrigatorio>
                                <select id="campo-material_servico" name="material_servico" class="input">
                                    <option value="M" @selected(old('material_servico', $editando?->material_servico) === 'M')>Material (CATMAT)</option>
                                    <option value="S" @selected(old('material_servico', $editando?->material_servico) === 'S')>Serviço (CATSER)</option>
                                </select>
                            </x-campo>
                            <x-campo nome="categoria" rotulo="Categoria" obrigatorio>
                                <select id="campo-categoria" name="categoria" class="input">
                                    @foreach ($cat as $v => $r)<option value="{{ $v }}" @selected((int) old('categoria', $editando?->categoria ?? 2) === $v)>{{ $r }}</option>@endforeach
                                </select>
                            </x-campo>
                            <x-campo nome="descricao" rotulo="Descrição" :valor="$editando?->descricao" obrigatorio classe="full" />
                            <x-campo nome="codigo_catalogo" rotulo="Código CATMAT/CATSER" :valor="$editando?->codigo_catalogo" inputmode="numeric" />
                            <x-campo nome="unidade_fornecimento" rotulo="Unidade" :valor="$editando?->unidade_fornecimento" obrigatorio placeholder="UN, CX, MÊS..." />
                            <x-campo nome="quantidade" rotulo="Quantidade" :valor="$editando ? numero_br($editando->quantidade, 0) : null" obrigatorio inputmode="decimal" />
                            <x-campo nome="valor_unitario_estimado" rotulo="Valor unitário estimado (R$)" :valor="$editando ? numero_br($editando->valor_unitario_estimado) : null" obrigatorio inputmode="decimal" />
                            <x-campo nome="unidade_id" rotulo="Unidade requisitante">
                                <select id="campo-unidade_id" name="unidade_id" class="input">
                                    <option value="">Não informada</option>
                                    @foreach ($unidades as $u)<option value="{{ $u->id }}" @selected((int) old('unidade_id', $editando?->unidade_id) === $u->id)>{{ $u->sigla }}</option>@endforeach
                                </select>
                            </x-campo>
                            <x-campo nome="data_desejada" rotulo="Data desejada" tipo="date" :valor="$editando?->data_desejada?->format('Y-m-d')" />
                            <x-campo nome="prioridade" rotulo="Prioridade" obrigatorio>
                                <select id="campo-prioridade" name="prioridade" class="input">
                                    @foreach (\App\Models\ItemPca::PRIORIDADES as $v => $r)<option value="{{ $v }}" @selected((int) old('prioridade', $editando?->prioridade ?? 2) === $v)>{{ $r }}</option>@endforeach
                                </select>
                            </x-campo>
                            <x-campo nome="natureza_despesa" rotulo="Natureza do objeto" :valor="$editando?->natureza_despesa" ajuda="Agrupa objetos da mesma natureza no controle de fracionamento (art. 75, § 1º). Ex.: 3.3.90.30.16" />
                        </div>
                        <div class="form-acoes">
                            @if ($editando)<a href="{{ route('pca.show', $plano) }}" class="btn btn-s">Cancelar</a>@endif
                            <button class="btn btn-p" type="submit">{{ $editando ? 'Salvar item' : 'Incluir item' }}</button>
                        </div>
                    </form>
                </section>
            @endif
        @endcan
    </div>

    @can('pca.gerenciar')
        <section class="card">
            <div class="row">
                <div>
                    <h2>Importar planilha</h2>
                    <p class="hint">Inclua muitos itens de uma vez, a partir de planilha XLSX ou CSV. Antes de gravar, você confere cada linha, com erros e duplicidades apontados.</p>
                </div>
                <a href="{{ route('pca.importacoes.modelo', $plano) }}" class="btn btn-s"><x-icone nome="download" />Baixar modelo</a>
            </div>
            @if ($plano->editavel())
                <form method="POST" action="{{ route('pca.importacoes.store', $plano) }}" enctype="multipart/form-data" class="form-grid" style="margin-top:12px">
                    @csrf
                    <div class="campo full"><label for="arquivo-pca">Planilha (.xlsx ou .csv, até 5 MB e {{ \App\Licitacao\Pca\PlanilhaPca::MAX_LINHAS }} itens)</label><input id="arquivo-pca" type="file" name="arquivo" class="input" accept=".xlsx,.csv" required>@error('arquivo')<span class="erro">{{ $message }}</span>@enderror</div>
                    <div class="full form-acoes"><button class="btn btn-p" type="submit"><x-icone nome="planilha" />Ler planilha</button></div>
                </form>
            @else
                <p class="muted small" style="margin-top:8px">O plano aprovado não recebe itens diretamente.</p>
            @endif
            @if ($importacoes->isNotEmpty())
                <div class="tbl-wrap" style="margin-top:14px"><table class="tbl">
                    <thead><tr><th>Arquivo</th><th>Enviada em</th><th>Situação</th><th class="num">Itens</th><th class="acoes"><span class="sr-only">Ações</span></th></tr></thead>
                    <tbody>
                        @foreach ($importacoes as $imp)
                            <tr>
                                <td>{{ $imp->arquivo_nome }}<div class="muted small">{{ $imp->usuario?->name }}</div></td>
                                <td>{{ $imp->created_at->format('d/m/Y H:i') }}</td>
                                <td><span class="b {{ $imp->pendente() ? 'b-info' : ($imp->situacao === 'confirmada' ? 'b-ok' : 'b-mute') }}">{{ \App\Models\ImportacaoPca::SITUACOES[$imp->situacao] }}</span></td>
                                <td class="num">{{ $imp->situacao === 'confirmada' ? $imp->importadas : $imp->validas }} de {{ $imp->total_linhas }}</td>
                                <td class="acoes"><a href="{{ route('pca.importacoes.show', [$plano, $imp]) }}" class="btn btn-s btn-sm"><x-icone nome="olho" tamanho="16" /><span class="sr-only">Ver importação de {{ $imp->arquivo_nome }}</span></a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endif
        </section>
    @endcan

    <div class="card card-flush">
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Nº</th><th>Descrição</th><th>Categoria</th><th>Unidade</th><th class="num">Qtd.</th><th class="num">Valor total</th><th>Prioridade</th><th class="acoes"><span class="sr-only">Ações</span></th></tr></thead>
                <tbody>
                    @forelse ($itens as $item)
                        <tr>
                            <td class="sigla">{{ $item->numero }}</td>
                            <td>{{ $item->descricao }}<div class="muted small">{{ $item->material_servico === 'M' ? 'Material' : 'Serviço' }}@if ($item->codigo_catalogo) · {{ $item->codigo_catalogo }}@endif @if ($item->data_desejada) · para {{ $item->data_desejada->format('m/Y') }}@endif</div></td>
                            <td class="muted">{{ $cat[$item->categoria] ?? '' }}</td>
                            <td>{{ $item->unidade?->sigla ?? '—' }}</td>
                            <td class="num">{{ numero_br($item->quantidade, 0) }} {{ $item->unidade_fornecimento }}</td>
                            <td class="num valor">{{ reais($item->valor_total_estimado) }}</td>
                            <td><span class="b {{ $item->prioridade === 1 ? 'b-warn' : 'b-mute' }}">{{ \App\Models\ItemPca::PRIORIDADES[$item->prioridade] }}</span></td>
                            <td class="acoes">
                                @can('pca.gerenciar')
                                    @if ($plano->editavel())
                                        <a href="{{ route('pca.show', [$plano, 'item' => $item->id]) }}" class="btn btn-s btn-sm"><x-icone nome="editar" tamanho="16" /><span class="sr-only">Editar</span></a>
                                        <form method="POST" action="{{ route('pca.itens.destroy', [$plano, $item]) }}" style="display:inline" onsubmit="return confirm('Excluir o item {{ $item->numero }}?')">@csrf @method('DELETE')<button class="btn btn-d btn-sm" type="submit"><x-icone nome="lixeira" tamanho="16" /><span class="sr-only">Excluir</span></button></form>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="vazio"><b>Nenhum item no plano</b>Inclua os itens a partir das demandas aceitas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
