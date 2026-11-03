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
    $abas = ['resumo' => 'Resumo', 'instrucao' => 'Instrução ('.$cumpridos.'/'.count($checklist).')', 'itens' => 'Itens e preços', 'resultado' => 'Resultado', 'historico' => 'Histórico', 'pncp' => 'PNCP'];
@endphp
<x-layouts.app :titulo="'Processo '.$processo->identificacao()">
    <div class="row">
        <div style="min-width:0">
            <a href="{{ route('processos.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Processos</a>
            <h1 class="h1" style="margin-top:8px">Processo {{ $processo->identificacao() }} <span class="b {{ $sit->badge() }}" style="vertical-align:middle">{{ $sit->rotulo() }}</span></h1>
            <p class="sub">{{ $processo->modalidadeEnum()->rotulo() }} · {{ $processo->amparoEnum()->rotulo() }} · {{ $processo->categoriaEnum()->rotulo() }}</p>
        </div>
        <div class="btns">
            @if ($podeInstruir)<a href="{{ route('processos.edit', $processo) }}" class="btn btn-s"><x-icone nome="editar" />Editar dados</a>@endif
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
                    @if ($processo->demanda)<div><dt>Demanda</dt><dd>{{ $processo->demanda->numero }}/{{ $processo->demanda->ano }}</dd></div>@endif
                </dl>
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
            <section class="card">
                <div class="row">
                    <div style="min-width:0"><h2>Item {{ $item->numero }} · {{ $item->descricao }}</h2><p class="hint">{{ numero_br($item->quantidade, 0) }} {{ $item->unidade_medida }} · {{ $item->material_servico === 'M' ? 'Material' : 'Serviço' }}@if ($item->codigo_catalogo) · {{ $item->codigo_catalogo }}@endif · {{ \App\Models\ItemProcesso::BENEFICIOS[$item->tipo_beneficio] }}</p></div>
                    <div style="text-align:right"><div class="valor" style="font-size:22px;font-weight:800">{{ reais($item->valor_total_estimado) }}</div><div class="small muted">unitário {{ reais($item->valor_unitario_estimado, 4) }} · {{ \App\Licitacao\Regras\PesquisaPrecos::METODOS[$item->metodo_preco] }}</div></div>
                </div>
                @foreach ($r['alertas'] as $a)<div class="flash flash-aviso" style="margin-top:12px"><x-icone nome="alerta" style="flex:none" /><div>{{ $a }}</div></div>@endforeach
                <div class="tbl-wrap" style="margin-top:12px">
                    <table class="tbl">
                        <thead><tr><th>Fonte</th><th>Referência</th><th>Data</th><th class="num">Valor unitário</th><th>Situação</th><th class="acoes"><span class="sr-only">Ações</span></th></tr></thead>
                        <tbody>
                            @forelse ($item->cotacoes as $i => $c)
                                <tr>
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
                                            <form method="POST" action="{{ route('processos.cotacoes.destroy', [$processo, $c]) }}" style="display:inline" onsubmit="return confirm('Excluir esta cotação?')">@csrf @method('DELETE')<button class="btn btn-d btn-sm" type="submit"><x-icone nome="lixeira" tamanho="16" /><span class="sr-only">Excluir</span></button></form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="muted">Nenhuma cotação ainda.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($r['coeficiente_variacao'] !== null)<p class="small muted" style="margin-top:8px">Coeficiente de variação das cotações válidas: {{ numero_br($r['coeficiente_variacao']) }}%{{ $r['coeficiente_variacao'] > 25 ? ' (pesquisa dispersa: reveja as fontes)' : '' }}.</p>@endif

                @if ($podeInstruir)
                    <details style="margin-top:12px">
                        <summary class="btn btn-s btn-sm" style="list-style:none">+ Registrar cotação</summary>
                        <form method="POST" action="{{ route('processos.cotacoes.store', [$processo, $item]) }}" class="form-grid" style="margin-top:14px" novalidate>
                            @csrf
                            <div class="campo"><label for="f-{{ $item->id }}-fonte">Fonte (art. 23, § 1º)</label><select id="f-{{ $item->id }}-fonte" name="fonte" class="input">@foreach (\App\Licitacao\Enums\FontePreco::opcoes() as $v => $rot)<option value="{{ $v }}">{{ $rot }}</option>@endforeach</select></div>
                            <div class="campo"><label for="f-{{ $item->id }}-ref">Referência</label><input id="f-{{ $item->id }}-ref" name="referencia" class="input" required placeholder="Fornecedor, órgão ou sítio"></div>
                            <div class="campo"><label for="f-{{ $item->id }}-doc">CNPJ (cotação direta)</label><input id="f-{{ $item->id }}-doc" name="documento_fonte" class="input" placeholder="00.000.000/0000-00"></div>
                            <div class="campo"><label for="f-{{ $item->id }}-val">Valor unitário (R$)</label><input id="f-{{ $item->id }}-val" name="valor_unitario" class="input" inputmode="decimal" required></div>
                            <div class="campo"><label for="f-{{ $item->id }}-data">Data</label><input id="f-{{ $item->id }}-data" name="data_cotacao" type="date" class="input" value="{{ today()->format('Y-m-d') }}" required></div>
                            <div class="campo" style="justify-content:flex-end"><button class="btn btn-p" type="submit">Registrar</button></div>
                        </form>
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

    @if ($aba === 'pncp')
        <section class="card">
            <h2>Publicações no PNCP</h2>
            <p class="hint">@if ($processo->numero_controle_pncp)Compra publicada sob a referência {{ $processo->numero_controle_pncp }}.@else Ainda não publicado. A publicação vai para a fila quando a autoridade autoriza.@endif</p>
            @include('pncp._lista', ['envios' => $processo->enviosPncp])
        </section>
    @endif
</x-layouts.app>
