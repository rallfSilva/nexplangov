@php($itensForm = old('itens', $demanda?->itens->map(fn ($i) => ['material_servico' => $i->material_servico, 'categoria' => $i->categoria, 'codigo_catalogo' => $i->codigo_catalogo, 'descricao' => $i->descricao, 'unidade_fornecimento' => $i->unidade_fornecimento, 'quantidade' => rtrim(rtrim(numero_br($i->quantidade, 4), '0'), ','), 'valor_unitario_estimado' => numero_br($i->valor_unitario_estimado, fmod((float) $i->valor_unitario_estimado * 100, 1) == 0 ? 2 : 4)])->all() ?? []))
@php($itensForm = $itensForm === [] ? [[]] : array_values($itensForm))
@php($categorias = \App\Licitacao\Enums\CategoriaProcesso::opcoes())
<x-layouts.app :titulo="$demanda ? 'Editar DFD '.$demanda->numero.'/'.$demanda->ano : 'Nova demanda'">
    <div>
        <a href="{{ route('demandas.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Demandas</a>
        <h1 class="h1" style="margin-top:8px">{{ $demanda ? 'Editar DFD '.$demanda->numero.'/'.$demanda->ano : 'Nova demanda' }}</h1>
        <p class="sub">Descreva o que a sua área precisa e por quê, item a item pelo catálogo CATMAT/CATSER. Isso fundamenta todo o processo. <span class="lei">art. 12, VII, e art. 19, II</span></p>
        @if ($demanda?->motivo_recusa)<div class="flash flash-aviso" role="status">Devolvido pelo planejamento: {{ $demanda->motivo_recusa }}</div>@endif
    </div>
    @foreach ($exercicios as $ex)
        @if ($p = $prazos->get($ex))
            @php($fechado = \App\Licitacao\Regras\PrazoDemanda::encerrado($p->data_limite, today()))
            <div class="flash {{ $fechado ? 'flash-aviso' : 'flash-ok' }}" role="status">Demandas para o PCA {{ $ex }}: {{ $fechado ? 'prazo encerrado em' : 'envie até' }} {{ $p->data_limite->format('d/m/Y') }}.@if ($fechado) Agora só entram as exceções justificadas.@endif <span class="lei">art. 12, VII</span></div>
        @endif
    @endforeach
    <form method="POST" action="{{ $demanda ? route('demandas.update', $demanda) : route('demandas.store') }}" class="card" novalidate>
        @csrf @if ($demanda) @method('PUT') @endif
        <div class="form-grid">
            <x-campo nome="exercicio" rotulo="Exercício do PCA" obrigatorio ajuda="Ano em que a contratação deve acontecer.">
                <select id="campo-exercicio" name="exercicio" class="input">
                    @foreach ($exercicios as $ex)<option value="{{ $ex }}" @selected((int) old('exercicio', $exercicioPadrao) === $ex)>{{ $ex }}</option>@endforeach
                </select>
            </x-campo>
            <x-campo nome="unidade_id" rotulo="Unidade requisitante" obrigatorio>
                <select id="campo-unidade_id" name="unidade_id" class="input">
                    <option value="">Escolha</option>
                    @foreach ($unidades as $u)<option value="{{ $u->id }}" @selected((int) old('unidade_id', $demanda?->unidade_id) === $u->id)>@if ($mostrarOrgao){{ $u->orgao?->sigla }} › @endif{{ $u->sigla }} · {{ $u->nome }}</option>@endforeach
                </select>
            </x-campo>
            <x-campo nome="item_pca_id" rotulo="Item do PCA" ajuda="Se a demanda não estiver no plano, a justificativa precisa dizer por quê.">
                <select id="campo-item_pca_id" name="item_pca_id" class="input">
                    <option value="">Não está no PCA</option>
                    @foreach ($itensPca as $i)<option value="{{ $i->id }}" data-exercicio="{{ $i->plano?->ano }}" @selected((int) old('item_pca_id', $demanda?->item_pca_id) === $i->id)>@if ($mostrarOrgao){{ $i->orgao?->sigla }} › @endif PCA {{ $i->plano?->ano }} · {{ $i->numero }} · {{ \Illuminate\Support\Str::limit($i->descricao, 70) }}</option>@endforeach
                </select>
            </x-campo>
            <x-campo nome="objeto" rotulo="O que precisa ser contratado" :valor="$demanda?->objeto" obrigatorio classe="full" maxlength="1000" />
            <x-campo nome="justificativa" rotulo="Justificativa da necessidade" obrigatorio classe="full">
                <textarea id="campo-justificativa" name="justificativa" class="input" rows="5">{{ old('justificativa', $demanda?->justificativa) }}</textarea>
            </x-campo>
            <x-campo nome="data_pretendida" rotulo="Para quando" tipo="date" :valor="$demanda?->data_pretendida?->format('Y-m-d')" obrigatorio />
            <x-campo nome="prioridade" rotulo="Prioridade" obrigatorio>
                <select id="campo-prioridade" name="prioridade" class="input">
                    @foreach (\App\Models\ItemPca::PRIORIDADES as $v => $r)<option value="{{ $v }}" @selected((int) old('prioridade', $demanda?->prioridade ?? 2) === $v)>{{ $r }}</option>@endforeach
                </select>
            </x-campo>
            <x-campo nome="vinculadas" rotulo="Demandas vinculadas" classe="full" ajuda="Indique demandas que dependem desta ou a complementam (ex.: equipamento e instalação). Segure Ctrl para escolher mais de uma.">
                <select id="campo-vinculadas" name="vinculadas[]" class="input" multiple size="4">
                    @foreach ($outrasDemandas as $o)<option value="{{ $o->id }}" @selected(in_array($o->id, array_map('intval', (array) old('vinculadas', $demanda?->vinculadas->pluck('id')->all() ?? [])), true))>{{ $o->numero }}/{{ $o->ano }} · {{ \Illuminate\Support\Str::limit($o->objeto, 80) }}</option>@endforeach
                </select>
            </x-campo>
            <x-campo nome="excecao_prazo" rotulo="Exceção ao prazo de envio" ajuda="Só depois da data-limite do exercício.">
                <select id="campo-excecao_prazo" name="excecao_prazo" class="input">
                    <option value="">Nenhuma</option>
                    @foreach (\App\Licitacao\Regras\PrazoDemanda::EXCECOES as $v => $r)<option value="{{ $v }}" @selected(old('excecao_prazo', $demanda?->excecao_prazo) === $v)>{{ $r }}</option>@endforeach
                </select>
            </x-campo>
            <x-campo nome="justificativa_excecao" rotulo="Justificativa da exceção" classe="full">
                <textarea id="campo-justificativa_excecao" name="justificativa_excecao" class="input" rows="2">{{ old('justificativa_excecao', $demanda?->justificativa_excecao) }}</textarea>
            </x-campo>
        </div>

        <fieldset class="itens-dfd" style="border:0;padding:0;margin:18px 0 0">
            <legend><h2 style="margin:0">Itens <span aria-hidden="true" style="color:#9F1D1D">*</span></h2></legend>
            <p class="hint">Busque no catálogo para trazer o código, a descrição e a unidade. O valor é a estimativa preliminar; a pesquisa de preços vem depois <span class="lei">art. 23</span></p>
            @error('itens')<span class="erro" role="alert">{{ $message }}</span>@enderror
            <div class="campo" style="margin-top:10px;position:relative">
                <label for="busca-catalogo">Buscar no catálogo CATMAT/CATSER</label>
                <input id="busca-catalogo" class="input" type="search" autocomplete="off" placeholder="Código ou palavras da descrição" data-url="{{ route('catalogo.buscar') }}" aria-controls="resultados-catalogo">
                <ul id="resultados-catalogo" class="card" role="listbox" hidden style="position:absolute;z-index:5;left:0;right:0;top:100%;max-height:280px;overflow:auto;list-style:none;margin:4px 0 0;padding:6px"></ul>
            </div>
            <div class="tbl-wrap" style="margin-top:10px">
                <table class="tbl" id="tabela-itens">
                    <thead><tr><th>Tipo</th><th>Categoria</th><th>Código</th><th>Descrição</th><th>Unidade</th><th class="num">Quantidade</th><th class="num">Valor unitário (R$)</th><th class="acoes"><span class="sr-only">Remover</span></th></tr></thead>
                    <tbody>
                        @foreach ($itensForm as $n => $it)
                            <tr class="linha-item">
                                <td><select name="itens[{{ $n }}][material_servico]" class="input" aria-label="Tipo do item {{ $n + 1 }}"><option value="M" @selected(($it['material_servico'] ?? 'M') === 'M')>Material</option><option value="S" @selected(($it['material_servico'] ?? '') === 'S')>Serviço</option></select></td>
                                <td><select name="itens[{{ $n }}][categoria]" class="input" aria-label="Categoria do item {{ $n + 1 }}">@foreach ($categorias as $v => $r)<option value="{{ $v }}" @selected((int) ($it['categoria'] ?? 2) === $v)>{{ $r }}</option>@endforeach</select></td>
                                <td><input name="itens[{{ $n }}][codigo_catalogo]" class="input" inputmode="numeric" value="{{ $it['codigo_catalogo'] ?? '' }}" aria-label="Código do item {{ $n + 1 }}" style="width:8em"></td>
                                <td><input name="itens[{{ $n }}][descricao]" class="input" value="{{ $it['descricao'] ?? '' }}" aria-label="Descrição do item {{ $n + 1 }}"></td>
                                <td><input name="itens[{{ $n }}][unidade_fornecimento]" class="input" value="{{ $it['unidade_fornecimento'] ?? '' }}" placeholder="UN" aria-label="Unidade do item {{ $n + 1 }}" style="width:6em"></td>
                                <td class="num"><input name="itens[{{ $n }}][quantidade]" class="input" inputmode="decimal" value="{{ $it['quantidade'] ?? '' }}" aria-label="Quantidade do item {{ $n + 1 }}" style="width:7em"></td>
                                <td class="num"><input name="itens[{{ $n }}][valor_unitario_estimado]" class="input" inputmode="decimal" value="{{ $it['valor_unitario_estimado'] ?? '' }}" aria-label="Valor unitário do item {{ $n + 1 }}" style="width:9em"></td>
                                <td class="acoes"><button type="button" class="btn btn-g btn-sm remover-item">Remover</button></td>
                            </tr>
                            @foreach (['material_servico', 'categoria', 'codigo_catalogo', 'descricao', 'unidade_fornecimento', 'quantidade', 'valor_unitario_estimado'] as $c)
                                @error("itens.$n.$c")<tr><td colspan="8"><span class="erro">{{ $message }}</span></td></tr>@enderror
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn btn-s btn-sm" id="adicionar-item" style="margin-top:8px"><x-icone nome="mais" />Adicionar item</button>
        </fieldset>
        <div class="form-acoes"><a href="{{ route('demandas.index') }}" class="btn btn-s">Cancelar</a><button class="btn btn-s" type="submit" name="acao" value="rascunho">Salvar em elaboração</button><button class="btn btn-p" type="submit" name="acao" value="enviar"><x-icone nome="enviar" />Enviar ao planejamento</button></div>
    </form>
    <script>
        (() => {
            const corpo = document.querySelector('#tabela-itens tbody');
            const renumerar = () => corpo.querySelectorAll('tr.linha-item').forEach((tr, n) => tr.querySelectorAll('[name^="itens["]').forEach(el => { el.name = el.name.replace(/^itens\[\d+\]/, `itens[${n}]`); }));
            const novaLinha = () => {
                const base = corpo.querySelector('tr.linha-item');
                const tr = base.cloneNode(true);
                tr.querySelectorAll('input').forEach(i => { i.value = ''; });
                tr.querySelectorAll('select').forEach(s => { s.selectedIndex = s.name.endsWith('[categoria]') ? [...s.options].findIndex(o => o.value === '2') : 0; });
                corpo.appendChild(tr);
                renumerar();
                return tr;
            };
            document.getElementById('adicionar-item').addEventListener('click', () => novaLinha().querySelector('input').focus());
            corpo.addEventListener('click', e => {
                if (!e.target.classList.contains('remover-item')) return;
                const linhas = corpo.querySelectorAll('tr.linha-item');
                const tr = e.target.closest('tr');
                if (linhas.length === 1) { tr.querySelectorAll('input').forEach(i => { i.value = ''; }); } else { tr.remove(); renumerar(); }
            });

            const busca = document.getElementById('busca-catalogo');
            const lista = document.getElementById('resultados-catalogo');
            let espera;
            busca.addEventListener('input', () => {
                clearTimeout(espera);
                const q = busca.value.trim();
                if (q.length < 2) { lista.hidden = true; return; }
                espera = setTimeout(async () => {
                    const r = await fetch(busca.dataset.url + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
                    const itens = r.ok ? await r.json() : [];
                    lista.replaceChildren(...(itens.length ? itens : [null]).map(it => {
                        const li = document.createElement('li');
                        if (!it) { li.textContent = 'Nada encontrado no catálogo. Preencha o item à mão.'; li.className = 'muted small'; return li; }
                        const b = document.createElement('button');
                        b.type = 'button'; b.className = 'btn btn-g btn-sm'; b.style.cssText = 'width:100%;justify-content:flex-start;text-align:left';
                        b.textContent = `${it.tipo === 'S' ? 'CATSER' : 'CATMAT'} ${it.codigo} · ${it.descricao}`;
                        b.addEventListener('click', () => {
                            const vazia = [...corpo.querySelectorAll('tr.linha-item')].find(tr => ![...tr.querySelectorAll('input')].some(i => i.value.trim()));
                            const tr = vazia || novaLinha();
                            tr.querySelector('[name$="[material_servico]"]').value = it.tipo;
                            tr.querySelector('[name$="[codigo_catalogo]"]').value = it.codigo;
                            tr.querySelector('[name$="[descricao]"]').value = it.descricao;
                            tr.querySelector('[name$="[unidade_fornecimento]"]').value = it.unidade_padrao || '';
                            if (it.tipo === 'S') tr.querySelector('[name$="[categoria]"]').value = '8';
                            lista.hidden = true; busca.value = '';
                            tr.querySelector('[name$="[quantidade]"]').focus();
                        });
                        li.appendChild(b);
                        return li;
                    }));
                    lista.hidden = false;
                }, 250);
            });
            document.addEventListener('click', e => { if (!lista.contains(e.target) && e.target !== busca) lista.hidden = true; });
        })();
    </script>
</x-layouts.app>
