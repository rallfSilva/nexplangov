{{-- Cotação automática por IA (art. 23, § 1º, II): planilha, pedido em fila, andamento, resumo por item e mapa de preços. --}}
@php
    $iaLigada = \App\Licitacao\Ia\AssistenteIa::habilitada() && auth()->user()->can('ia.usar');
    $semPreco = $processo->itens->filter(fn ($i) => (float) $i->valor_unitario_estimado <= 0)->count();
    $nfeLigada = \App\Integracoes\NotasFiscais\PrecosNotasFiscais::habilitada();
    $nomesEstat = ['minimo' => 'Mínimo', 'media' => 'Média', 'mediana' => 'Mediana', 'media_ponderada' => 'Média pond.'];
@endphp
<section class="card">
    <div class="row">
        <div style="min-width:0">
            <h2 style="display:flex;align-items:center;gap:8px"><x-icone nome="ia" />Pesquisa de preços</h2>
            <p class="hint">Preços já praticados no último ano em três fontes: Painel de Preços do governo federal (<span class="lei">art. 23, § 1º, I</span>), licitações anteriores de outros órgãos no PNCP (<span class="lei">inciso II</span>) e notas fiscais eletrônicas (<span class="lei">inciso V</span>). Cada preço mostra a fonte da coleta e o link do registro original. A NexMind IA confere se o objeto é equivalente; o cálculo segue a regra do sistema.</p>
        </div>
        <div class="btns">
            @if ($podeInstruir)
                @if ($iaLigada && $processo->itens->isNotEmpty())
                    <button type="button" class="btn btn-p" onclick="document.getElementById('dlg-cotar-ia').showModal()" @disabled($loteIa?->emAndamento())><x-icone nome="ia" />Cotar com IA</button>
                @endif
                <details class="duplicar">
                    <summary class="btn btn-s"><x-icone nome="planilha" />Importar planilha</summary>
                    <div class="card" style="position:absolute;right:0;z-index:5;width:min(360px,calc(100vw - 32px));margin-top:6px">
                        <p class="small" style="margin:0 0 10px;line-height:1.5">Baixe o modelo, preencha um item por linha e envie. Com qualquer linha errada, nenhum item entra.</p>
                        <a class="btn btn-s btn-sm" href="{{ route('processos.itens.modelo', $processo) }}"><x-icone nome="download" tamanho="16" />Baixar modelo</a>
                        <form method="POST" action="{{ route('processos.itens.importar', $processo) }}" enctype="multipart/form-data" style="margin-top:10px">
                            @csrf
                            <label class="small muted" for="planilha-itens">Planilha (.xlsx ou .csv)</label>
                            <input id="planilha-itens" type="file" name="planilha" accept=".xlsx,.csv" class="input" required>
                            <button class="btn btn-p btn-sm" type="submit" style="margin-top:10px">Importar</button>
                        </form>
                    </div>
                </details>
            @endif
            @if ($processo->itens->isNotEmpty())
                <details class="duplicar">
                    <summary class="btn btn-s"><x-icone nome="download" />Mapa de preços (PDF)</summary>
                    <form method="GET" action="{{ route('processos.mapa-precos', $processo) }}" target="_blank" class="card" style="position:absolute;right:0;z-index:5;width:min(320px,calc(100vw - 32px));margin-top:6px">
                        <input type="hidden" name="configurado" value="1">
                        <p class="small" style="margin:0 0 6px"><b>Colunas</b></p>
                        @foreach ($nomesEstat as $v => $rot)<label class="small" style="display:block"><input type="checkbox" name="colunas[]" value="{{ $v }}" checked> {{ $rot === 'Média pond.' ? 'Média ponderada' : $rot }}</label>@endforeach
                        <p class="small" style="margin:10px 0 6px"><b>Seções</b></p>
                        <label class="small" style="display:block"><input type="checkbox" name="cotacoes" value="1" checked> Cotações de cada item, com a fonte</label>
                        <label class="small" style="display:block"><input type="checkbox" name="descartadas" value="1" checked> Cotações fora do cálculo e o motivo</label>
                        <label class="small" style="display:block"><input type="checkbox" name="justificativa" value="1" checked> Justificativa da pesquisa</label>
                        <button class="btn btn-p btn-sm" type="submit" style="margin-top:10px">Gerar PDF</button>
                    </form>
                </details>
            @endif
        </div>
    </div>

    @if ($loteIa?->emAndamento())
        <div id="andamento-ia" data-url="{{ route('processos.cotacao-ia.andamento', $processo) }}" style="margin-top:14px">
            <div class="small" style="display:flex;justify-content:space-between;margin-bottom:6px"><span><x-icone nome="relogio" tamanho="16" /> Cotando com IA na fila. Você recebe um e-mail quando terminar.</span><b id="andamento-ia-txt">{{ $loteIa->itens_processados }} de {{ $loteIa->total_itens }}</b></div>
            <div class="progresso" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $loteIa->percentual() }}"><span id="andamento-ia-barra" style="width:{{ $loteIa->percentual() }}%"></span></div>
        </div>
        <script>
            (() => {
                const el = document.getElementById('andamento-ia');
                const tick = async () => {
                    try {
                        const r = await (await fetch(el.dataset.url, { headers: { Accept: 'application/json' } })).json();
                        document.getElementById('andamento-ia-txt').textContent = `${r.processados} de ${r.total}`;
                        document.getElementById('andamento-ia-barra').style.width = `${r.percentual}%`;
                        if (r.situacao === 'concluido') { location.reload(); return; }
                    } catch (e) {}
                    setTimeout(tick, 5000);
                };
                setTimeout(tick, 5000);
            })();
        </script>
    @elseif ($loteIa && $loteIa->concluido_em?->gt(now()->subDay()))
        <div class="flash flash-ok" style="margin-top:14px"><x-icone nome="ok" style="flex:none" /><div>Cotação por IA concluída em {{ $loteIa->concluido_em->format('d/m/Y H:i') }}: {{ $loteIa->itens_cotados }} de {{ $loteIa->total_itens }} item(ns) com preços. Confira as cotações e a justificativa de cada item.</div></div>
    @endif

    @if ($processo->itens->isNotEmpty())
        <div class="tbl-wrap" style="margin-top:14px">
            <table class="tbl">
                <thead><tr><th>Item</th><th>Descrição</th><th class="num">Qtd.</th>@foreach ($nomesEstat as $rot)<th class="num">{{ $rot }}</th>@endforeach<th>Situação</th></tr></thead>
                <tbody>
                    @foreach ($processo->itens as $item)
                        @php($r = $precos[$item->id])
                        <tr>
                            <td><a href="#item-{{ $item->id }}">{{ $item->numero }}</a></td>
                            <td style="max-width:360px">{{ \Illuminate\Support\Str::limit($item->descricao, 90) }}</td>
                            <td class="num">{{ numero_br($item->quantidade, 0) }} {{ $item->unidade_medida }}</td>
                            @foreach (array_keys($nomesEstat) as $k)<td class="num valor">{{ reais($r['estatisticas'][$k], 2) }}</td>@endforeach
                            <td class="small">
                                @if ($r['discrepante'])<span class="b b-warn" title="Coeficiente de variação {{ numero_br($r['coeficiente_variacao']) }}%"><x-icone nome="alerta" tamanho="14" /> Discrepante</span>@endif
                                @switch($item->cotacao_ia_situacao)
                                    @case('na_fila')<span class="b b-info">Na fila da IA</span>@break
                                    @case('cotado')<span class="b b-ok">Cotado pela IA</span>@break
                                    @case('sem_resultado')<span class="b b-mute" title="{{ $item->cotacao_ia_mensagem }}">IA sem resultado</span>@break
                                    @case('erro')<span class="b b-err" title="{{ $item->cotacao_ia_mensagem }}">Erro na IA</span>@break
                                @endswitch
                                @if (! $r['discrepante'] && ! $item->cotacao_ia_situacao && $r['quantidade_validas'] === 0)<span class="b b-mute">Sem preço</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

@if ($podeInstruir && $iaLigada && $processo->itens->isNotEmpty())
    <dialog id="dlg-cotar-ia" class="modal" aria-labelledby="dlg-cotar-ia-t">
        <form method="POST" action="{{ route('processos.cotacao-ia', $processo) }}">
            @csrf
            <h2 id="dlg-cotar-ia-t" style="display:flex;align-items:center;gap:8px"><x-icone nome="ia" />Cotar com IA</h2>
            <p class="small" style="line-height:1.5">A cotação é processada em uma fila e você recebe um e-mail quando terminar. Para cada item, a IA procura o código CATMAT/CATSER (se faltar), busca preços do último ano nas fontes abaixo, fica só com os de objeto equivalente e equilibra a escolha entre as fontes. Cotações lançadas à mão são mantidas; as da IA anterior são trocadas.</p>
            <p class="small" style="margin:10px 0 4px"><b>Fontes consultadas</b></p>
            <ul class="small" style="margin:0;padding-left:18px;line-height:1.6">
                <li>Painel de Preços do governo federal, Compras.gov.br (<span class="lei">art. 23, § 1º, I</span>) <span class="b b-ok">Ativa</span></li>
                <li>Licitações anteriores de outros órgãos, PNCP (<span class="lei">inciso II</span>) <span class="b b-ok">Ativa</span></li>
                <li>Notas fiscais eletrônicas (<span class="lei">inciso V</span>) @if ($nfeLigada)<span class="b b-ok">Ativa</span>@else<span class="b b-mute" title="Não há base pública aberta de NF-e: o acesso depende de convênio com a Receita Federal ou a SEFAZ">Depende de convênio</span>@endif</li>
            </ul>
            <fieldset style="border:0;padding:0;margin:12px 0">
                <legend class="small"><b>Quais itens</b></legend>
                <label class="small" style="display:block"><input type="radio" name="escopo" value="sem_preco" @checked($semPreco > 0) @disabled($semPreco === 0)> Só os itens sem preço ({{ $semPreco }})</label>
                <label class="small" style="display:block"><input type="radio" name="escopo" value="todos" @checked($semPreco === 0)> Todos os itens ({{ $processo->itens->count() }})</label>
            </fieldset>
            <label class="small" for="resultados-ia"><b>Quantidade de resultados por item</b></label>
            <select id="resultados-ia" name="resultados" class="input">@foreach (\App\Models\LoteCotacaoIa::RESULTADOS as $n)<option value="{{ $n }}" @selected($n === 3)>{{ $n }} resultados</option>@endforeach</select>
            <p class="small muted" style="margin-top:6px">Com menos de 3 preços válidos, o sistema alerta e pede justificativa no processo (<span class="lei">art. 23, § 1º</span>).</p>
            <div class="btns" style="justify-content:flex-end;margin-top:16px">
                <button type="button" class="btn btn-s" onclick="this.closest('dialog').close()">Cancelar</button>
                <button type="submit" class="btn btn-p"><x-icone nome="ia" />Processar com IA</button>
            </div>
        </form>
    </dialog>
@endif
