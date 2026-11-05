@php
    use App\Licitacao\Enums\SituacaoProcesso as S;
    $sit = $processo->situacaoEnum();
    $direta = $processo->modalidadeEnum()->ehContratacaoDireta();
    $fluxo = $direta
        ? [S::Planejamento, S::AnaliseJuridica, S::AguardandoAutorizacao, S::Publicado, S::Homologado, S::Contratado]
        : [S::Planejamento, S::AnaliseJuridica, S::AguardandoAutorizacao, S::Publicado, S::EmJulgamento, S::Homologado, S::Contratado];
    $pos = array_search($sit, $fluxo, true);
    $cumpridos = collect($checklist)->where('cumprido', true)->count();
    $podeInstruir = auth()->user()->can('processos.gerenciar') && $processo->editavel();
    $abas = ['resumo' => 'Resumo', 'instrucao' => 'Instrução ('.$cumpridos.'/'.count($checklist).')', 'itens' => 'Itens e preços', 'resultado' => 'Resultado', 'historico' => 'Histórico', 'pncp' => 'PNCP', 'tramitacao' => 'Tramitação', 'anotacoes' => 'Anotações internas'.($totalAnotacoes ? ' ('.$totalAnotacoes.')' : '')];
@endphp
<x-layouts.app :titulo="'Processo '.$processo->identificacao()">
    <div class="row">
        <div style="min-width:0">
            <a href="{{ route('processos.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Processos</a>
            <h1 class="h1" style="margin-top:8px">Processo {{ $processo->identificacao() }} <span class="b {{ $sit->badge() }}" style="vertical-align:middle">{{ $sit->rotulo() }}</span></h1>
            <p class="sub">{{ $processo->modalidadeEnum()->rotulo() }} · {{ $processo->amparoEnum()->rotulo() }} · {{ $processo->categoriaEnum()->rotulo() }}
                · <a href="{{ route('processos.show', [$processo, 'aba' => 'tramitacao']) }}">@if ($processo->arquivado())<span class="b b-mute">arquivado em {{ $processo->unidadeAtual?->sigla }}</span>@elseif ($remessaPendente)<span class="b b-warn">em trânsito para {{ $remessaPendente->destino?->sigla }}</span>@else está em {{ $processo->unidadeAtual?->sigla ?? 'unidade não informada' }} @endif</a></p>
        </div>
        <div class="btns">
            @if ($podeInstruir)<a href="{{ route('processos.edit', $processo) }}" class="btn btn-s"><x-icone nome="editar" />Editar dados</a>@endif
            @can('processos.gerenciar')
                <details class="duplicar">
                    <summary class="btn btn-s">Duplicar</summary>
                    <form method="POST" action="{{ route('processos.duplicar', $processo) }}" class="card" style="position:absolute;right:0;z-index:5;width:320px;margin-top:6px">
                        @csrf
                        <p class="small" style="margin:0 0 10px;line-height:1.5">Cria um processo novo na fase preparatória com os dados, os itens, as cotações e as peças do editor como rascunho. Parecer, autorização, habilitação, propostas e resultado não são copiados.</p>
                        <x-campo nome="numero" rotulo="Número do novo processo" :obrigatorio="true" />
                        <x-campo nome="ano" rotulo="Ano" tipo="number" :valor="now()->year" :obrigatorio="true" />
                        <button type="submit" class="btn btn-p btn-sm" style="margin-top:10px">Duplicar processo</button>
                    </form>
                </details>
            @endcan
            @can('contratos.gerenciar')
                @if ($sit === S::Homologado)<a href="{{ route('contratos.create', ['processo' => $processo->id]) }}" class="btn btn-p"><x-icone nome="contrato" />Cadastrar contrato</a>@endif
            @endcan
        </div>
    </div>

    @if ($pos !== false)
        <div class="card">
            <div class="etapas" aria-label="Etapas do processo">
                @foreach ($fluxo as $i => $etapa)
                    <div class="etapa {{ $i < $pos ? 'feita' : ($i === $pos ? 'atual' : '') }}">
                        <span class="bola">@if ($i < $pos)<x-icone nome="ok" tamanho="14" />@else{{ $i + 1 }}@endif</span>
                        {{ $etapa->rotulo() }}
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($destinos)
        <section class="card">
            <h2>Próximo passo</h2>
            <p class="hint">O sistema confere as exigências da Lei 14.133 antes de cada passagem.</p>
            <div class="btns" style="margin-top:14px">
                @foreach ($destinos as $destino => $d)
                    <form method="POST" action="{{ route('processos.tramitar', $processo) }}" class="btns" @if ($d['justificativa']) onsubmit="const j = prompt('Justificativa (fica pública no processo):'); if (!j) return false; this.justificativa.value = j;" @endif>
                        @csrf
                        <input type="hidden" name="destino" value="{{ $destino }}">
                        <input type="hidden" name="justificativa">
                        <button type="submit" class="btn {{ in_array($destino, ['revogado', 'anulado'], true) ? 'btn-d' : ($d['justificativa'] ? 'btn-s' : 'btn-p') }}">{{ $d['rotulo'] }}</button>
                    </form>
                @endforeach
            </div>
        </section>
    @endif

    <nav class="abas" aria-label="Seções do processo">
        @foreach ($abas as $chave => $rotulo)
            <a href="{{ route('processos.show', [$processo, 'aba' => $chave]) }}" class="{{ $aba === $chave ? 'on' : '' }}">{{ $rotulo }}</a>
        @endforeach
    </nav>

    @if ($aba === 'resumo')
        <div class="g2">
            <section class="card">
                <h2>Objeto</h2>
                <p style="margin:10px 0 0;line-height:1.6">{{ $processo->objeto }}</p>
                <dl class="dados">
                    <div><dt>Valor estimado</dt><dd class="valor">{{ reais($processo->valor_estimado) }}@if ($processo->orcamento_sigiloso) <span class="b b-warn">sigiloso</span>@endif</dd></div>
                    @if ($processo->valor_homologado !== null)<div><dt>Valor homologado</dt><dd class="valor">{{ reais($processo->valor_homologado) }}</dd></div>@endif
                    <div><dt>Unidade</dt><dd>{{ $processo->unidade?->sigla ?? '—' }}</dd></div>
                    <div><dt>Responsável</dt><dd>{{ $processo->responsavel?->name ?? '—' }}</dd></div>
                    <div><dt>Critério</dt><dd>{{ \App\Licitacao\Enums\CriterioJulgamento::from($processo->criterio_julgamento)->rotulo() }}</dd></div>
                    <div><dt>Registro de preços</dt><dd>{{ $processo->srp ? 'Sim' : 'Não' }}</dd></div>
                    @if ($processo->abertura_propostas_em)<div><dt>Propostas</dt><dd>{{ $processo->abertura_propostas_em->format('d/m/Y H:i') }} a {{ $processo->encerramento_propostas_em?->format('d/m/Y H:i') }}</dd></div>@endif
                    @if ($processo->origem)<div><dt>Duplicado de</dt><dd><a href="{{ route('processos.show', $processo->origem) }}">{{ $processo->origem->identificacao() }}</a></dd></div>@endif
                    @if ($processo->demanda)<div><dt>Demanda</dt><dd>{{ $processo->demanda->numero }}/{{ $processo->demanda->ano }}</dd></div>@endif
                    <div><dt>Previsão no PCA</dt><dd>
                        @if ($processo->itemPca)Item {{ $processo->itemPca->numero }} do PCA {{ $processo->itemPca->plano?->ano }}
                        @elseif ($processo->previsaoPcaResolvida())<span class="b b-warn">fora do plano, justificado</span>
                        @else<span class="b b-err">sem previsão nem justificativa</span>@endif
                    </dd></div>
                </dl>
                @if (! $processo->itemPca && $processo->justificativa_fora_pca)
                    <p class="small" style="margin:10px 0 0;line-height:1.6"><b>Justificativa para contratar fora do PCA</b> <span class="lei">art. 12, VII</span><br>{{ $processo->justificativa_fora_pca }}</p>
                @endif
                <div class="btns" style="margin-top:14px;align-items:flex-end">
                    @can('processos.gerenciar')
                        <form method="POST" action="{{ route('processos.sei', $processo) }}" class="btns">@csrf @method('PATCH')
                            <label class="small" for="numero_sei">Número SEI</label><input id="numero_sei" name="numero_sei" class="input" style="width:200px" value="{{ $processo->numero_sei }}" placeholder="0000000.000000/2026-00"><button class="btn btn-s btn-sm" type="submit">Salvar</button></form>
                    @elseif ($processo->numero_sei)<span class="small">SEI {{ $processo->numero_sei }}</span>@endcan
                    @if ($processo->numero_sei && config('nexplangov.sei.url_consulta'))<a class="btn btn-g btn-sm" target="_blank" rel="noopener" href="{{ str_replace('{numero}', urlencode($processo->numero_sei), config('nexplangov.sei.url_consulta')) }}">Abrir no SEI</a>@endif
                    <a class="btn btn-s btn-sm" href="{{ route('processos.dossie', $processo) }}"><x-icone nome="download" tamanho="16" />Dossiê para o SEI</a>
                </div>
            </section>
            @if ($dispensa)
                <section class="card">
                    <h2>Limite da dispensa por valor</h2>
                    @if (isset($dispensa['erro']))
                        <div class="flash flash-erro" style="margin-top:12px">{{ $dispensa['erro'] }}</div>
                    @else
                        @php($uso = $dispensa['limite'] > 0 ? min(100, $dispensa['total'] / $dispensa['limite'] * 100) : 100)
                        <p class="hint">{{ $dispensa['dispositivo'] }} · {{ $dispensa['ato'] }}@unless ($dispensa['conferido']) <span class="b b-warn">valor a conferir</span>@endunless</p>
                        <div style="margin:18px 0 8px" class="row"><b class="valor">{{ reais($dispensa['total']) }}</b><span class="muted small">de {{ reais($dispensa['limite']) }}</span></div>
                        <div class="barra {{ ! $dispensa['permitido'] ? 'estourou' : ($uso > 80 ? 'alerta' : '') }}"><span style="width:{{ round($uso, 1) }}%"></span></div>
                        <p class="small muted" style="margin-top:10px">Já contratado no ano para a natureza {{ $processo->natureza_despesa }}: {{ reais($dispensa['ja_contratado']) }} em {{ count($dispensa['processos']) }} processo(s). {{ $dispensa['permitido'] ? 'Dentro do limite.' : 'Ultrapassa o limite: é preciso licitar (art. 75, § 1º).' }}</p>
                    @endif
                </section>
            @else
                <section class="card">
                    <h2>Instrução</h2>
                    <p class="hint">{{ $cumpridos }} de {{ count($checklist) }} exigências cumpridas.</p>
                    <div class="barra" style="margin-top:16px"><span style="width:{{ round($cumpridos / max(1, count($checklist)) * 100) }}%"></span></div>
                    <a href="{{ route('processos.show', [$processo, 'aba' => 'instrucao']) }}" class="btn btn-s btn-sm" style="margin-top:16px">Ver checklist</a>
                </section>
            @endif
        </div>
    @endif

    @if ($aba === 'instrucao')
        <section class="card">
            <h2>Checklist da instrução</h2>
            <p class="hint">{{ $direta ? 'Contratação direta: art. 72, incisos I a VIII.' : 'Fase preparatória: art. 18 e parecer do art. 53.' }} Cada peça concluída fica com hash e não muda mais; alterar gera nova versão.</p>
            @if ($fluxos->isNotEmpty())
                @can('processos.gerenciar')
                    <form method="POST" action="{{ route('processos.fluxo', $processo) }}" class="filtros" style="margin-top:8px">@csrf
                        <div class="campo"><label for="fluxo-sel">Fluxo de documentos deste processo</label><select id="fluxo-sel" name="fluxo_id" class="input">@foreach ($fluxos as $f)<option value="{{ $f->id }}" @selected($processo->fluxo_id === $f->id)>{{ $f->nome }}</option>@endforeach</select></div>
                        <div class="btns"><button class="btn btn-s btn-sm" type="submit">Aplicar fluxo</button></div>
                    </form>
                @endcan
            @endif
            <div class="checklist" style="margin-top:8px">
                @foreach ($checklist as $item)
                    <div class="check-item {{ $item['cumprido'] ? 'ok' : 'pendente' }}">
                        <span class="marca"><x-icone :nome="$item['cumprido'] ? 'ok' : 'relogio'" tamanho="16" /></span>
                        <div style="flex:1;min-width:0">
                            <b>{{ $item['rotulo'] }}</b>
                            <div class="base">{{ $item['base'] }}@if ($item['detalhe']) · {{ $item['detalhe'] }}@endif
                                @if (isset($documentos[$item['chave']])) · versão {{ $documentos[$item['chave']]->versao }}@endif
                                @if (isset($estados[$item['chave']]) && ($etapas->has($item['chave']) || isset($documentos[$item['chave']]))) · <span class="b {{ $estados[$item['chave']]['classe'] }}">{{ $estados[$item['chave']]['rotulo'] }}</span>@endif
                                @if (($e = $etapas[$item['chave']] ?? null) && $e->prazo_em && ! $item['cumprido']) · prazo {{ $e->prazo_em->format('d/m/Y') }}@if ($e->prazoVencido()) <span class="b b-err">vencido</span>@endif @endif
                                @if (! $item['cumprido'] && ($estados[$item['chave']]['pendencias'] ?? [])) · depende de {{ implode(', ', $estados[$item['chave']]['pendencias']) }}@endif</div>
                        </div>
                        @if (isset($tipos[$item['chave']]))
                            <a href="{{ route('documentos.edit', [$processo, $item['chave']]) }}" class="btn btn-s btn-sm">{{ isset($documentos[$item['chave']]) ? 'Abrir' : 'Preencher' }}</a>
                        @elseif ($item['chave'] === 'previsao_pca' && ! $item['cumprido'] && $podeInstruir)
                            <a href="{{ route('processos.edit', $processo) }}" class="btn btn-s btn-sm">Indicar</a>
                        @elseif ($item['chave'] === 'pesquisa_precos')
                            <a href="{{ route('processos.show', [$processo, 'aba' => 'itens']) }}" class="btn btn-s btn-sm">Ir aos preços</a>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($aba === 'itens')
        @foreach ($processo->itens as $item)
            @php($r = $precos[$item->id])
            <section class="card" id="item-{{ $item->id }}">
                <div class="row">
                    <div style="min-width:0"><h2>Item {{ $item->numero }} · {{ $item->descricao }}</h2><p class="hint">{{ numero_br($item->quantidade, 0) }} {{ $item->unidade_medida }} · {{ $item->material_servico === 'M' ? 'Material' : 'Serviço' }}@if ($item->codigo_catalogo) · {{ $item->codigo_catalogo }}@endif · {{ \App\Models\ItemProcesso::BENEFICIOS[$item->tipo_beneficio] }}</p></div>
                    <div style="text-align:right"><div class="valor" style="font-size:22px;font-weight:800">{{ reais($item->valor_total_estimado) }}</div><div class="small muted">unitário {{ reais($item->valor_unitario_estimado, 4) }} · {{ \App\Licitacao\Regras\PesquisaPrecos::METODOS[$item->metodo_preco] }}</div>
                        @if ($podeInstruir)
                            <div class="btns" style="justify-content:flex-end;margin-top:8px">
                                <a class="btn btn-s btn-sm" href="{{ route('processos.show', [$processo, 'aba' => 'itens', 'item' => $item->id]) }}#item-{{ $item->id }}"><x-icone nome="editar" tamanho="16" />Editar item</a>
                                <form method="POST" action="{{ route('processos.itens.destroy', [$processo, $item]) }}" style="display:inline" onsubmit="return confirm('Excluir o item {{ $item->numero }} e todas as suas cotações?')">@csrf @method('DELETE')<button class="btn btn-d btn-sm" type="submit"><x-icone nome="lixeira" tamanho="16" />Excluir item</button></form>
                            </div>
                        @endif
                    </div>
                </div>
                @if ($podeInstruir && ((int) request('item') === $item->id || old('form_item') === "editar-item-{$item->id}"))
                    @include('processos._form-item')
                @endif
                @foreach ($r['alertas'] as $a)<div class="flash flash-aviso" style="margin-top:12px"><x-icone nome="alerta" style="flex:none" /><div>{{ $a }}</div></div>@endforeach
                <div class="tbl-wrap" style="margin-top:12px">
                    <table class="tbl">
                        <thead><tr><th>Fonte</th><th>Referência</th><th>Data</th><th class="num">Valor unitário</th><th>Situação</th><th class="acoes"><span class="sr-only">Ações</span></th></tr></thead>
                        <tbody>
                            @forelse ($item->cotacoes as $i => $c)
                                @php($editando = $podeInstruir && ((int) request('cotacao') === $c->id || old('form_cotacao') === "editar-{$c->id}"))
                                <tr id="cotacao-{{ $c->id }}">
                                    <td class="small">{{ $c->fonteEnum()->rotulo() }}</td>
                                    <td>{{ $c->referencia }}@if ($c->documento_fonte)<div class="muted small">{{ $c->documento_fonte }}</div>@endif</td>
                                    <td>{{ $c->data_cotacao->format('d/m/Y') }}</td>
                                    <td class="num valor">{{ reais($c->valor_unitario, 4) }}</td>
                                    <td>@if (isset($r['descartadas'][$i]))<span class="b b-warn" title="{{ $r['descartadas'][$i] }}">Fora do cálculo</span><div class="small muted">{{ $r['descartadas'][$i] }}</div>@else<span class="b b-ok">Válida</span>@endif</td>
                                    <td class="acoes">
                                        @if ($podeInstruir)
                                            @unless ($c->desconsiderada)
                                                <form method="POST" action="{{ route('processos.cotacoes.desconsiderar', [$processo, $c]) }}" style="display:inline" onsubmit="const m = prompt('Motivo para desconsiderar esta cotação:'); if (!m) return false; this.motivo_desconsideracao.value = m;">@csrf @method('PATCH')<input type="hidden" name="motivo_desconsideracao"><button class="btn btn-g btn-sm" type="submit">Desconsiderar</button></form>
                                            @endunless
                                            <a class="btn btn-s btn-sm" href="{{ route('processos.show', [$processo, 'aba' => 'itens', 'cotacao' => $c->id]) }}#cotacao-{{ $c->id }}" title="Editar cotação"><x-icone nome="editar" tamanho="16" /><span class="sr-only">Editar cotação de {{ $c->referencia }}</span></a>
                                            <form method="POST" action="{{ route('processos.cotacoes.destroy', [$processo, $c]) }}" style="display:inline" onsubmit="return confirm('Excluir esta cotação?')">@csrf @method('DELETE')<button class="btn btn-d btn-sm" type="submit"><x-icone nome="lixeira" tamanho="16" /><span class="sr-only">Excluir</span></button></form>
                                        @endif
                                    </td>
                                </tr>
                                @if ($editando)
                                    <tr class="linha-edicao"><td colspan="6"><b>Editar cotação</b> <span class="small muted">· o preço de referência é recalculado ao salvar</span>@include('processos._form-cotacao', ['cotacao' => $c])</td></tr>
                                @endif
                            @empty
                                <tr><td colspan="6" class="muted">Nenhuma cotação ainda.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($r['coeficiente_variacao'] !== null)<p class="small muted" style="margin-top:8px">Coeficiente de variação das cotações válidas: {{ numero_br($r['coeficiente_variacao']) }}%{{ $r['coeficiente_variacao'] > 25 ? ' (pesquisa dispersa: reveja as fontes)' : '' }}.</p>@endif

                @if ($podeInstruir)
                    @if ($item->codigo_catalogo)<a class="btn btn-s btn-sm" style="margin-top:12px" href="{{ route('processos.comprasgov', [$processo, $item]) }}"><x-icone nome="busca" tamanho="16" />Buscar preços no Compras.gov.br</a>@endif
                    <details style="margin-top:12px" @if (old('form_cotacao') === "nova-{$item->id}") open @endif>
                        <summary class="btn btn-s btn-sm" style="list-style:none">+ Registrar cotação</summary>
                        @include('processos._form-cotacao')
                    </details>
                    <form method="POST" action="{{ route('processos.itens.metodo', [$processo, $item]) }}" class="btns" style="margin-top:10px">
                        @csrf @method('PATCH')
                        <label class="small muted" for="m-{{ $item->id }}">Preço de referência pela</label>
                        <select id="m-{{ $item->id }}" name="metodo_preco" class="input" style="width:auto;min-height:36px" onchange="this.form.submit()">@foreach (\App\Licitacao\Regras\PesquisaPrecos::METODOS as $v => $rot)<option value="{{ $v }}" @selected($item->metodo_preco === $v)>{{ $rot }}</option>@endforeach</select>
                    </form>
                @endif
            </section>
        @endforeach

        @if ($podeInstruir)
            <section class="card">
                <h2>Incluir item</h2>
                <form method="POST" action="{{ route('processos.itens.store', $processo) }}" class="form-grid" style="margin-top:14px" novalidate>
                    @csrf
                    <x-campo nome="descricao" rotulo="Descrição" obrigatorio classe="full" />
                    <x-campo nome="material_servico" rotulo="Tipo" obrigatorio><select id="campo-material_servico" name="material_servico" class="input"><option value="M">Material</option><option value="S">Serviço</option></select></x-campo>
                    <x-campo nome="codigo_catalogo" rotulo="CATMAT/CATSER" inputmode="numeric" />
                    <x-campo nome="unidade_medida" rotulo="Unidade" obrigatorio />
                    <x-campo nome="quantidade" rotulo="Quantidade" obrigatorio inputmode="decimal" />
                    <x-campo nome="tipo_beneficio" rotulo="Benefício ME/EPP" obrigatorio><select id="campo-tipo_beneficio" name="tipo_beneficio" class="input">@foreach (\App\Models\ItemProcesso::BENEFICIOS as $v => $rot)<option value="{{ $v }}" @selected($v === 4)>{{ $rot }}</option>@endforeach</select></x-campo>
                    <div class="campo" style="justify-content:flex-end"><button class="btn btn-p" type="submit"><x-icone nome="mais" />Incluir</button></div>
                </form>
            </section>
        @endif
    @endif

    @if ($aba === 'resultado')
        @unless (\App\Licitacao\Enums\Modalidade::from($processo->modalidade)->ehContratacaoDireta())
            <div class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                <span class="small muted" style="margin-right:auto">Propostas, lances, habilitação e adjudicação (arts. 33, 59 a 71) e pedidos ao edital (art. 164).</span>
                <a class="btn btn-s btn-sm" href="{{ route('licitacao.show', $processo) }}">Sessão de julgamento</a>
                <a class="btn btn-s btn-sm" href="{{ route('impugnacoes.index', $processo) }}">Impugnações e esclarecimentos</a>
            </div>
        @endunless
        <section class="card card-flush">
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead><tr><th>Item</th><th class="num">Estimado</th><th>Vencedor</th><th class="num">Valor homologado</th><th>Situação</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($processo->itens as $item)
                            @php($pode = auth()->user()->can('processos.gerenciar') && in_array($processo->situacao, ['em_julgamento', 'publicado'], true))
                            <tr>
                                <td><b>{{ $item->numero }}</b> {{ \Illuminate\Support\Str::limit($item->descricao, 60) }}</td>
                                <td class="num valor">{{ reais($item->valor_unitario_estimado, 4) }}</td>
                                @if ($pode)
                                    <td colspan="4">
                                        <form method="POST" action="{{ route('processos.itens.resultado', [$processo, $item]) }}" class="btns">
                                            @csrf @method('PATCH')
                                            <select name="fornecedor_vencedor_id" class="input" style="width:auto;min-width:220px" aria-label="Vencedor do item {{ $item->numero }}"><option value="">Vencedor</option>@foreach ($fornecedores as $f)<option value="{{ $f->id }}" @selected($item->fornecedor_vencedor_id === $f->id)>{{ $f->razao_social }}</option>@endforeach</select>
                                            <input name="valor_unitario_homologado" class="input" style="width:150px" inputmode="decimal" placeholder="Valor unitário" value="{{ $item->valor_unitario_homologado ? numero_br($item->valor_unitario_homologado, 4) : '' }}" aria-label="Valor homologado">
                                            <select name="situacao" class="input" style="width:auto" aria-label="Situação"><option value="pendente">Com vencedor</option><option value="cancelado" @selected($item->situacao === 'cancelado')>Cancelado</option><option value="deserto" @selected($item->situacao === 'deserto')>Deserto</option></select>
                                            <button class="btn btn-s btn-sm" type="submit">Salvar</button>
                                        </form>
                                    </td>
                                @else
                                    <td>{{ $item->vencedor?->razao_social ?? '—' }}</td>
                                    <td class="num valor">{{ $item->valor_unitario_homologado ? reais($item->valor_unitario_homologado, 4) : '—' }}</td>
                                    <td><span class="b b-mute">{{ \App\Models\ItemProcesso::SITUACOES[$item->situacao] }}</span></td><td></td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        @if ($processo->contratos->isNotEmpty())
            <section class="card"><h2>Contratos</h2><div class="list">@foreach ($processo->contratos as $c)<div class="li"><a href="{{ route('contratos.show', $c) }}" class="sigla">{{ $c->identificacao() }}</a><span class="r valor">{{ reais($c->valor_global) }}</span></div>@endforeach</div></section>
        @endif
    @endif

    @if ($aba === 'historico')
        <section class="card">
            <h2>Tramitação</h2>
            <div class="tempo">
                @forelse ($processo->eventos as $e)
                    <div class="ev"><span class="ponto"></span><div><b>{{ $e->rotuloPara() }}</b><div class="small muted">{{ $e->usuario?->name ?? 'Sistema' }} · {{ $e->created_at->format('d/m/Y H:i') }}</div>@if ($e->justificativa)<p style="margin:6px 0 0" class="small">{{ $e->justificativa }}</p>@endif</div></div>
                @empty
                    <p class="muted">Ainda na fase preparatória, sem passagens registradas.</p>
                @endforelse
            </div>
        </section>
    @endif

    @if ($aba === 'tramitacao')
        @php($eu = auth()->user())
        @php($podeTramitar = $eu->can('processos.tramitar'))
        @php($naMinha = $eu->atuaNaUnidade($processo->unidade_atual_id))
        @php($siglas = \App\Models\Unidade::pluck('sigla', 'id'))
        @php($nomes = \App\Models\User::whereIn('id', collect($periodos)->pluck('responsavel')->filter())->pluck('name', 'id'))
        <div class="g2">
            <section class="card">
                <h2>Onde o processo está</h2>
                <dl class="dados">
                    <div><dt>Unidade atual</dt><dd>{{ $processo->unidadeAtual ? $processo->unidadeAtual->sigla.' · '.$processo->unidadeAtual->nome : '—' }}</dd></div>
                    <div><dt>Situação dos autos</dt><dd>
                        @if ($processo->arquivado())<span class="b b-mute">Arquivado em {{ $processo->arquivado_em->format('d/m/Y') }}</span>
                        @elseif ($remessaPendente)<span class="b b-warn">Em trânsito para {{ $remessaPendente->destino?->sigla }} desde {{ $remessaPendente->enviado_em->format('d/m/Y H:i') }}</span>
                        @else<span class="b b-ok">Na unidade</span>@endif
                    </dd></div>
                    <div><dt>Unidade de origem</dt><dd>{{ $processo->unidade?->sigla ?? '—' }}</dd></div>
                </dl>
                @if ($podeTramitar && $remessaPendente)
                    <div class="btns" style="margin-top:14px">
                        @if ($eu->atuaNaUnidade($remessaPendente->unidade_destino_id))
                            <form method="POST" action="{{ route('tramitacao.receber', [$processo, $remessaPendente]) }}">@csrf<button type="submit" class="btn btn-p btn-sm">Receber na {{ $remessaPendente->destino?->sigla }}</button></form>
                        @endif
                        @if ($remessaPendente->enviado_por === $eu->id || $eu->atuaNaUnidade($remessaPendente->unidade_origem_id))
                            <details><summary class="btn btn-s btn-sm">Cancelar remessa</summary>
                                <form method="POST" action="{{ route('tramitacao.cancelar', [$processo, $remessaPendente]) }}" style="margin-top:8px">@csrf
                                    <label for="motivo-cancelamento" class="small">Motivo (antes do recebimento)</label>
                                    <textarea id="motivo-cancelamento" name="motivo" class="input" rows="2" maxlength="2000" required></textarea>
                                    <button type="submit" class="btn btn-d btn-sm" style="margin-top:6px">Cancelar a remessa</button>
                                </form>
                            </details>
                        @endif
                    </div>
                @endif
            </section>

            @if ($podeTramitar && ! $remessaPendente)
                <section class="card">
                    @if ($processo->arquivado())
                        <h2>Desarquivar</h2>
                        @if ($naMinha)
                            <form method="POST" action="{{ route('tramitacao.desarquivar', $processo) }}">@csrf
                                <x-campo nome="motivo" rotulo="Por que o processo volta a tramitar" :obrigatorio="true"><textarea id="campo-motivo" name="motivo" class="input" rows="3" maxlength="2000" required>{{ old('motivo') }}</textarea></x-campo>
                                <button type="submit" class="btn btn-s btn-sm" style="margin-top:8px">Desarquivar</button>
                            </form>
                        @else
                            <p class="muted small">Só quem está lotado na unidade onde o processo foi arquivado pode desarquivá-lo.</p>
                        @endif
                    @elseif ($naMinha)
                        <h2>Enviar a outra unidade</h2>
                        <form method="POST" action="{{ route('tramitacao.enviar', $processo) }}">@csrf
                            <x-campo nome="unidade_destino_id" rotulo="Unidade de destino" :obrigatorio="true">
                                <select id="campo-unidade_destino_id" name="unidade_destino_id" class="input" required>
                                    <option value="">Escolha</option>
                                    @foreach ($unidadesAtivas as $un)
                                        @continue($un->id === $processo->unidade_atual_id)
                                        <option value="{{ $un->id }}" @selected((int) old('unidade_destino_id', $sugestaoDestino ?? 0) === $un->id)>{{ $un->sigla }} · {{ $un->nome }}@if (($sugestaoDestino ?? null) === $un->id) (próxima do roteiro) @endif</option>
                                    @endforeach
                                </select>
                            </x-campo>
                            <x-campo nome="despacho" rotulo="Despacho" :obrigatorio="true"><textarea id="campo-despacho" name="despacho" class="input" rows="3" maxlength="5000" required placeholder="Ex.: encaminho para análise e parecer jurídico (art. 53).">{{ old('despacho') }}</textarea></x-campo>
                            <button type="submit" class="btn btn-p btn-sm" style="margin-top:8px"><x-icone nome="enviar" tamanho="16" />Enviar</button>
                        </form>
                        @if ($processo->situacaoEnum()->encerrado())
                            <details style="margin-top:16px"><summary class="small" style="cursor:pointer;font-weight:600">Arquivar na unidade</summary>
                                <form method="POST" action="{{ route('tramitacao.arquivar', $processo) }}" style="margin-top:8px">@csrf
                                    <label for="despacho-arquivo" class="small">Despacho de arquivamento</label>
                                    <textarea id="despacho-arquivo" name="despacho" class="input" rows="2" maxlength="5000" required></textarea>
                                    <button type="submit" class="btn btn-s btn-sm" style="margin-top:6px">Arquivar</button>
                                </form>
                            </details>
                        @endif
                    @else
                        <h2>Enviar a outra unidade</h2>
                        <p class="muted small">O processo está em {{ $processo->unidadeAtual?->sigla ?? 'outra unidade' }}. Só quem está lotado lá pode enviá-lo ou arquivá-lo.</p>
                    @endif
                </section>
            @endif
        </div>

        <section class="card">
            <h2>Tempo de permanência</h2>
            <p class="hint">Do recebimento até a saída de cada unidade, com quem recebeu. O tempo em trânsito vai do envio ao recebimento.</p>
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead><tr><th>Onde</th><th>Desde</th><th>Até</th><th>Responsável</th><th class="num">Dias</th></tr></thead>
                    <tbody>
                        @foreach ($periodos as $per)
                            <tr>
                                <td>@if ($per['transito'])<span class="b b-warn">Em trânsito</span>@else{{ $siglas[$per['unidade']] ?? '—' }}@endif</td>
                                <td>{{ $per['inicio']->format('d/m/Y H:i') }}</td>
                                <td>{{ $per['fim']?->format('d/m/Y H:i') ?? 'agora' }}</td>
                                <td>{{ $per['responsavel'] ? ($nomes[$per['responsavel']] ?? '—') : '—' }}</td>
                                <td class="num">{{ number_format($per['dias'], 1, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <h2>Histórico de tramitação</h2>
            <div class="tempo">
                @forelse ($tramites as $t)
                    @php($sit = $t->situacao())
                    <div class="ev"><span class="ponto"></span><div>
                        <b>@if ($t->tipo === 'remessa'){{ $t->origem?->sigla ?? '—' }} → {{ $t->destino?->sigla ?? '—' }}@else{{ \App\Models\TramiteProcesso::TIPOS[$t->tipo] }} em {{ $t->origem?->sigla ?? '—' }}@endif</b> <span class="b {{ $sit[1] }}">{{ $sit[0] }}</span>
                        <div class="small muted">{{ $t->remetente?->name ?? 'Usuário removido' }} · {{ $t->enviado_em->format('d/m/Y H:i') }}@if ($t->recebido_em) · recebido por {{ $t->recebedor?->name }} em {{ $t->recebido_em->format('d/m/Y H:i') }} @endif @if ($t->cancelado_em) · cancelada por {{ $t->cancelador?->name }} em {{ $t->cancelado_em->format('d/m/Y H:i') }} @endif</div>
                        @if ($t->despacho)<p style="margin:6px 0 0;white-space:pre-line" class="small">{{ $t->despacho }}</p>@endif
                        @if ($t->motivo_cancelamento)<p style="margin:6px 0 0" class="small"><b>Motivo do cancelamento:</b> {{ $t->motivo_cancelamento }}</p>@endif
                    </div></div>
                @empty
                    <p class="muted">O processo ainda não saiu da unidade de origem.</p>
                @endforelse
            </div>
        </section>
    @endif

    @if ($aba === 'anotacoes')
        <section class="card">
            <h2>Anotações internas</h2>
            <p class="hint">Rascunho de trabalho entre os servidores do processo. Não compõe os autos: não entra no checklist, no dossiê, nas exportações nem no PNCP. O que precisa constar do processo vai nas peças ou na justificativa da tramitação.</p>
            @if ($podeAnotar)
                <form method="POST" action="{{ route('processos.anotacoes.store', $processo) }}" style="margin-top:14px">
                    @csrf
                    <label for="texto-anotacao" class="sr-only">Nova anotação</label>
                    <textarea id="texto-anotacao" name="texto" class="input" rows="3" maxlength="5000" required placeholder="Ex.: conferir com o requisitante a quantidade do item 3 antes de mandar ao jurídico.">{{ old('texto') }}</textarea>
                    @error('texto')<p class="erro small">{{ $message }}</p>@enderror
                    <button type="submit" class="btn btn-p btn-sm" style="margin-top:8px">Anotar</button>
                </form>
            @endif
            <div class="tempo" style="margin-top:18px">
                @forelse ($anotacoes as $n)
                    <div class="ev" id="anotacao-{{ $n->id }}"><span class="ponto"></span><div style="flex:1;min-width:0">
                        <b>{{ $n->autor?->name ?? 'Usuário removido' }}</b> <span class="small muted">{{ $n->created_at->format('d/m/Y H:i') }}@if ($n->updated_at->gt($n->created_at)) · editada @endif</span>
                        <p style="margin:6px 0 0;white-space:pre-line" class="small">{{ $n->texto }}</p>
                        @if ($n->user_id === auth()->id())
                            <details style="margin-top:6px"><summary class="small">Editar ou excluir</summary>
                                <form method="POST" action="{{ route('processos.anotacoes.update', [$processo, $n]) }}" style="margin-top:6px">@csrf @method('PUT')
                                    <textarea name="texto" class="input" rows="3" maxlength="5000" required aria-label="Texto da anotação">{{ $n->texto }}</textarea>
                                    <div class="btns" style="margin-top:6px"><button type="submit" class="btn btn-s btn-sm">Salvar</button></div>
                                </form>
                                <form method="POST" action="{{ route('processos.anotacoes.destroy', [$processo, $n]) }}" onsubmit="return confirm('Excluir a anotação?')" style="margin-top:6px">@csrf @method('DELETE')<button type="submit" class="btn btn-d btn-sm">Excluir</button></form>
                            </details>
                        @endif
                    </div></div>
                @empty
                    <p class="muted">Nenhuma anotação.</p>
                @endforelse
            </div>
        </section>
    @endif

    @if ($aba === 'pncp')
        <section class="card">
            <h2>Publicações no PNCP</h2>
            <p class="hint">@if ($processo->numero_controle_pncp)Compra publicada sob a referência {{ $processo->numero_controle_pncp }}.@else Ainda não publicado. A publicação vai para a fila quando a autoridade autoriza.@endif</p>
            @can('pncp.enviar')
                @if ($processo->numero_controle_pncp && in_array($sit, [S::Homologado, S::Contratado], true))
                    <form method="POST" action="{{ route('pncp.resultados', $processo) }}" class="btns">@csrf<button class="btn btn-s btn-sm" type="submit"><x-icone nome="pncp" />Publicar resultado dos itens</button>
                        <span class="small muted">Vai sozinho na homologação; use para reenviar depois de corrigir. <span class="lei">art. 71, IV</span></span></form>
                @endif
            @endcan
            @include('pncp._lista', ['envios' => $processo->enviosPncp])
        </section>
    @endif
</x-layouts.app>
