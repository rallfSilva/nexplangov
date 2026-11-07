<x-layouts.app :titulo="'Ata '.$ata->identificacao()">
    @php($gerencia = auth()->user()->can('contratos.gerenciar'))
    @php($pode = $gerencia && $ata->vigente())
    <div>
        <a href="{{ route('atas.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Atas</a>
        <h1 class="h1" style="margin-top:8px">Ata {{ $ata->identificacao() }} <span class="b {{ $ata->vigente() ? 'b-ok' : 'b-mute' }}" style="vertical-align:middle">{{ $ata->situacaoExibida() }}</span></h1>
        <p class="sub">{{ $ata->fornecedor->razao_social }} · processo <a href="{{ route('processos.show', $ata->processo) }}">{{ $ata->processo->identificacao() }}</a> · vigência {{ $ata->vigencia_inicio->format('d/m/Y') }} a {{ $ata->vigencia_fim->format('d/m/Y') }}@if ($ata->prorrogada) (prorrogada)@endif</p>
        @if ($ata->motivo_cancelamento)<p class="small">Cancelada: {{ $ata->motivo_cancelamento }}</p>@endif
        @can('pncp.enviar')
            <form method="POST" action="{{ route('pncp.ata', $ata) }}" style="margin-top:10px">@csrf<button class="btn btn-s" type="submit"><x-icone nome="pncp" />{{ $ata->numero_controle_pncp ? 'Retificar no PNCP' : 'Publicar no PNCP' }}</button>
                <span class="small muted">{{ $ata->numero_controle_pncp ? 'Ata nº '.$ata->numero_controle_pncp.' no PNCP.' : 'O PDF da ata segue logo depois da publicação.' }} <span class="lei">art. 174, § 2º, II</span></span></form>
        @endcan
    </div>

    @foreach ($ata->itens as $ai)
        <section class="card">
            <h2 style="margin:0">Item {{ $ai->item->numero }}: {{ \Illuminate\Support\Str::limit($ai->item->descricao, 90) }}</h2>
            <div class="kpis" style="margin-top:10px">
                <div class="card kpi"><span class="lbl">Registrado</span><span class="val">{{ numero_br($ai->quantidade_registrada, 2) }}</span><span class="foot">{{ $ai->item->unidade_medida }} a {{ reais($ai->valor_unitario, 4) }}</span></div>
                <div class="card kpi"><span class="lbl">Saldo</span><span class="val">{{ numero_br($ai->saldo(), 2) }}</span><div class="barra {{ $ai->saldo() <= (float) $ai->quantidade_registrada * 0.1 ? 'alerta' : '' }}"><span style="width:{{ (float) $ai->quantidade_registrada > 0 ? min(100, $ai->consumido() / (float) $ai->quantidade_registrada * 100) : 0 }}%"></span></div></div>
                <div class="card kpi"><span class="lbl">Adesões autorizadas</span><span class="val">{{ numero_br($ai->autorizadoEmAdesoes(), 2) }}</span><span class="foot">limite {{ numero_br((float) $ai->quantidade_registrada * 2, 2) }} (dobro, art. 86, § 5º)</span></div>
            </div>

            @if ($pode)
                <form method="POST" action="{{ route('atas.consumo', [$ata, $ai]) }}" class="btns" style="margin-top:10px">@csrf
                    <input name="quantidade" class="input" style="width:110px" inputmode="decimal" placeholder="Quantidade" aria-label="Quantidade" required>
                    <input name="referencia" class="input" style="width:240px" placeholder="Empenho ou ordem de fornecimento" aria-label="Referência" required>
                    <select name="unidade_id" class="input" style="width:auto" aria-label="Unidade"><option value="">Unidade</option>@foreach ($unidades as $u)<option value="{{ $u->id }}">{{ $u->sigla }}</option>@endforeach</select>
                    <button class="btn btn-s btn-sm" type="submit">Registrar consumo</button></form>
            @endif

            @if ($ai->adesoes->isNotEmpty())
                <h3 style="margin:14px 0 4px">Adesões (carona) <span class="lei">art. 86, § 2º</span></h3>
                @foreach ($ai->adesoes as $ad)
                    <div class="aviso-lei" style="margin:8px 0">
                        <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:space-between">
                            <div><b>{{ $ad->aderente_nome }}</b> · {{ $ad->aderente_cnpj }} · {{ \App\Models\AtaAdesao::ESFERAS[$ad->aderente_esfera] ?? $ad->aderente_esfera }}@if ($ad->aderente_municipio) · {{ $ad->aderente_municipio }}/{{ $ad->aderente_uf }}@endif
                                <div class="small muted">{{ $ad->protocolo }} · {{ $ad->origem === 'publico' ? 'pedido pela página pública' : 'registrado pelo gerenciador' }} · {{ numero_br($ad->quantidade, 2) }} {{ $ai->item->unidade_medida }}@if ($ad->solicitante_nome) · {{ $ad->solicitante_nome }} ({{ $ad->solicitante_cargo }}){{ $ad->solicitante_email ? ', '.$ad->solicitante_email : '' }}@endif</div></div>
                            <span class="b {{ $ad->situacao === 'autorizada' && ! $ad->prazoEncerrado() ? 'b-ok' : ($ad->situacao === 'negada' || $ad->fornecedor_aceite === 'recusado' ? 'b-err' : 'b-info') }}" style="align-self:flex-start">{{ $ad->situacaoExibida() }}</span>
                        </div>
                        <div class="small" style="margin-top:6px"><b>Justificativa:</b> {{ $ad->justificativa }}<br><b>Vantagem e preços de mercado:</b> {{ $ad->vantagem }}</div>
                        @if ($ad->fornecedor_aceite)<div class="small" style="margin-top:4px"><b>Fornecedor {{ $ad->fornecedor_aceite === 'aceito' ? 'aceitou' : 'recusou' }}</b> em {{ $ad->fornecedor_aceite_em->format('d/m/Y H:i') }} · {{ $ad->fornecedor_aceite_representante }}@if ($ad->fornecedor_aceite_obs) · {{ $ad->fornecedor_aceite_obs }}@endif</div>@endif
                        @if ($ad->motivo_decisao)<div class="small">Decisão: {{ $ad->motivo_decisao }}</div>@endif
                        @if ($ad->prazo_contratacao)<div class="small">Contratar até <b>{{ $ad->prazo_contratacao->format('d/m/Y') }}</b> · consumido {{ numero_br($ad->consumido(), 2) }} de {{ numero_br($ad->quantidade, 2) }}</div>@endif
                        <div class="btns" style="margin-top:6px">
                            @if ($ad->oficio_caminho)<a class="btn btn-s btn-sm" href="{{ route('atas.adesao.arquivo', [$ata, $ad, 'oficio']) }}"><x-icone nome="download" />Ofício do aderente</a>@endif
                            @if ($ad->fornecedor_aceite_caminho)<a class="btn btn-s btn-sm" href="{{ route('atas.adesao.arquivo', [$ata, $ad, 'aceite']) }}"><x-icone nome="download" />Documento do fornecedor</a>@endif
                            @if ($ad->autorizacao_caminho)<a class="btn btn-s btn-sm" href="{{ route('atas.adesao.arquivo', [$ata, $ad, 'autorizacao']) }}"><x-icone nome="download" />Ofício de autorização (PDF)</a>@endif
                        </div>

                        @if ($pode && $ad->situacao === 'solicitada' && $ad->fornecedor_aceite === null)
                            @php($_link = $carona->linkAceite($ad))
                            <div class="campo" style="margin-top:8px"><label for="link-{{ $ad->id }}">Link para o fornecedor responder (vale {{ \App\Support\Parametros::valor('carona_link_aceite_dias', $ad->orgao_id) }} dias; envie por e-mail oficial)</label>
                                <input id="link-{{ $ad->id }}" class="input" value="{{ $_link }}" readonly onclick="this.select()"></div>
                            <details style="margin-top:6px"><summary class="small" style="cursor:pointer;font-weight:600">Ou registre a resposta que o fornecedor enviou por escrito</summary>
                                <form method="POST" action="{{ route('atas.adesao.aceite', [$ata, $ad]) }}" enctype="multipart/form-data" class="form-grid" style="margin-top:8px">@csrf
                                    <div class="campo"><label for="resp-{{ $ad->id }}">Resposta</label><select id="resp-{{ $ad->id }}" name="resposta" class="input"><option value="aceito">Aceitou</option><option value="recusado">Recusou</option></select></div>
                                    <div class="campo"><label for="rep-{{ $ad->id }}">Representante do fornecedor (nome e cargo)</label><input id="rep-{{ $ad->id }}" name="representante" class="input" required></div>
                                    <div class="campo"><label for="obs-{{ $ad->id }}">Observação</label><input id="obs-{{ $ad->id }}" name="obs" class="input"></div>
                                    <div class="campo"><label for="doc-{{ $ad->id }}">Documento do fornecedor (PDF ou imagem)</label><input id="doc-{{ $ad->id }}" type="file" name="documento" class="input" required></div>
                                    <div class="form-acoes full"><button class="btn btn-s btn-sm" type="submit">Registrar resposta</button></div>
                                </form>
                            </details>
                        @endif

                        @if ($pode && $ad->situacao === 'solicitada')
                            <form method="POST" action="{{ route('atas.adesao.decidir', [$ata, $ad]) }}" class="btns" style="margin-top:8px">@csrf
                                <input name="motivo" class="input" style="width:240px" placeholder="Motivo (obrigatório para negar)" aria-label="Motivo da decisão">
                                @if ($ad->fornecedor_aceite === 'aceito')<button class="btn btn-p btn-sm" name="acao" value="autorizar">Autorizar e emitir ofício</button>@endif
                                <button class="btn btn-s btn-sm" name="acao" value="negar">Negar</button>
                                @if ($ad->fornecedor_aceite === null)<span class="small muted">A autorização depende do aceite do fornecedor.</span>@endif
                            </form>
                        @elseif ($pode && $ad->situacao === 'autorizada' && ! $ad->prazoEncerrado())
                            <form method="POST" action="{{ route('atas.consumo', [$ata, $ai]) }}" class="btns" style="margin-top:8px">@csrf<input type="hidden" name="adesao_id" value="{{ $ad->id }}">
                                <input name="quantidade" class="input" style="width:90px" inputmode="decimal" placeholder="Qtd" aria-label="Quantidade consumida pelo aderente" required>
                                <input name="referencia" class="input" style="width:200px" placeholder="Contrato ou empenho do aderente" aria-label="Referência do consumo" required>
                                <button class="btn btn-s btn-sm" type="submit">Registrar consumo do aderente</button></form>
                        @endif
                    </div>
                @endforeach
            @endif

            @if ($pode)
                <details style="margin-top:10px"><summary class="btn btn-s btn-sm" style="list-style:none">+ Pedido de adesão (carona) recebido por ofício</summary>
                    <form method="POST" action="{{ route('atas.adesao', [$ata, $ai]) }}" enctype="multipart/form-data" class="form-grid" style="margin-top:12px">@csrf
                        <div class="campo"><label for="an-{{ $ai->id }}">Órgão aderente</label><input id="an-{{ $ai->id }}" name="aderente_nome" class="input" required></div>
                        <div class="campo"><label for="ac-{{ $ai->id }}">CNPJ do aderente</label><input id="ac-{{ $ai->id }}" name="aderente_cnpj" class="input" required></div>
                        <div class="campo"><label for="ae-{{ $ai->id }}">Esfera</label><select id="ae-{{ $ai->id }}" name="aderente_esfera" class="input">@foreach (\App\Models\AtaAdesao::ESFERAS as $v => $r)<option value="{{ $v }}">{{ $r }}</option>@endforeach</select></div>
                        <div class="campo"><label for="am-{{ $ai->id }}">Município</label><input id="am-{{ $ai->id }}" name="aderente_municipio" class="input"></div>
                        <div class="campo"><label for="au-{{ $ai->id }}">UF</label><input id="au-{{ $ai->id }}" name="aderente_uf" class="input" maxlength="2"></div>
                        <div class="campo"><label for="aq-{{ $ai->id }}">Quantidade</label><input id="aq-{{ $ai->id }}" name="quantidade" class="input" inputmode="decimal" required></div>
                        <div class="campo full"><label for="aj-{{ $ai->id }}">Justificativa da adesão</label><textarea id="aj-{{ $ai->id }}" name="justificativa" class="input" rows="2" required></textarea></div>
                        <div class="campo full"><label for="av-{{ $ai->id }}">Vantagem e compatibilidade dos preços com o mercado (art. 86, § 2º, I e II)</label><textarea id="av-{{ $ai->id }}" name="vantagem" class="input" rows="2" required></textarea></div>
                        <div class="campo full"><label for="ao-{{ $ai->id }}">Ofício do aderente (PDF)</label><input id="ao-{{ $ai->id }}" type="file" name="oficio" class="input" accept="application/pdf"></div>
                        <div class="form-acoes full"><button class="btn btn-p" type="submit">Registrar pedido</button></div></form>
                </details>
                <p class="small muted" style="margin-top:6px">Órgãos de fora também podem pedir sozinhos, sem conta, em <a href="{{ route('carona.ata', $ata) }}" target="_blank" rel="noopener">{{ route('carona.ata', $ata) }}</a>.</p>
            @endif
        </section>
    @endforeach

    @if ($gerencia && $ata->situacao === 'vigente')
        <section class="card"><h2>Prorrogar ou cancelar</h2>
            @if (! $ata->prorrogada && $ata->vigencia_fim->gte(today()))
                <form method="POST" action="{{ route('atas.prorrogar', $ata) }}" class="form-grid">@csrf
                    <x-campo nome="vigencia_fim" rotulo="Novo fim da vigência (máx. 2 anos no total)" tipo="date" obrigatorio />
                    <div class="campo full"><label for="{{ $_rot = 'c'.\Illuminate\Support\Str::random(8) }}">Comprovação de que o preço continua vantajoso (art. 84)</label><textarea id="{{ $_rot }}" name="vantagem" class="input" rows="2" required></textarea></div>
                    <div class="form-acoes full"><button class="btn btn-s" type="submit">Prorrogar</button></div></form>
            @endif
            <form method="POST" action="{{ route('atas.cancelar', $ata) }}" class="btns" style="margin-top:12px">@csrf<input name="motivo" class="input" style="width:320px" placeholder="Motivo do cancelamento" required minlength="10"><button class="btn btn-s" type="submit">Cancelar ata</button></form>
        </section>
    @endif
</x-layouts.app>
