<x-layouts.app :titulo="'Ata '.$ata->identificacao()">
    @php($gerencia = auth()->user()->can('contratos.gerenciar'))
    @php($pode = $gerencia && $ata->vigente())
    <div>
        <a href="{{ route('atas.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Atas</a>
        <h1 class="h1" style="margin-top:8px">Ata {{ $ata->identificacao() }} <span class="b {{ $ata->vigente() ? 'b-ok' : 'b-mute' }}" style="vertical-align:middle">{{ $ata->situacaoExibida() }}</span></h1>
        <p class="sub">{{ $ata->fornecedor->razao_social }} · processo <a href="{{ route('processos.show', $ata->processo) }}">{{ $ata->processo->identificacao() }}</a> · vigência {{ $ata->vigencia_inicio->format('d/m/Y') }} a {{ $ata->vigencia_fim->format('d/m/Y') }}@if ($ata->prorrogada) (prorrogada)@endif</p>
        @if ($ata->motivo_cancelamento)<p class="small">Cancelada: {{ $ata->motivo_cancelamento }}</p>@endif
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
                <div class="tbl-wrap" style="margin-top:10px"><table class="tbl"><thead><tr><th>Aderente</th><th class="num">Quantidade</th><th>Situação</th><th></th></tr></thead><tbody>
                    @foreach ($ai->adesoes as $ad)
                        <tr><td><b>{{ $ad->aderente_nome }}</b><div class="small muted">{{ $ad->aderente_cnpj }} · {{ $ad->vantagem }}</div></td><td class="num valor">{{ numero_br($ad->quantidade, 2) }}</td>
                            <td><span class="b {{ $ad->situacao === 'autorizada' ? 'b-ok' : ($ad->situacao === 'negada' ? 'b-err' : 'b-info') }}">{{ ['solicitada' => 'Solicitada', 'autorizada' => 'Autorizada', 'negada' => 'Negada'][$ad->situacao] }}</span>@if ($ad->motivo_decisao)<div class="small muted">{{ $ad->motivo_decisao }}</div>@endif</td>
                            <td class="acoes">
                                @if ($pode && $ad->situacao === 'solicitada')
                                    <form method="POST" action="{{ route('atas.adesao.decidir', [$ata, $ad]) }}" class="btns">@csrf<input name="motivo" class="input" style="width:170px" placeholder="Motivo (se negar)"><button class="btn btn-s btn-sm" name="acao" value="autorizar">Autorizar</button><button class="btn btn-s btn-sm" name="acao" value="negar">Negar</button></form>
                                @elseif ($pode && $ad->situacao === 'autorizada')
                                    <form method="POST" action="{{ route('atas.consumo', [$ata, $ai]) }}" class="btns">@csrf<input type="hidden" name="adesao_id" value="{{ $ad->id }}"><input name="quantidade" class="input" style="width:90px" inputmode="decimal" placeholder="Qtd" required><input name="referencia" class="input" style="width:150px" placeholder="Referência" required><button class="btn btn-s btn-sm" type="submit">Consumir</button></form>
                                @endif
                            </td></tr>
                    @endforeach
                </tbody></table></div>
            @endif

            @if ($pode)
                <details style="margin-top:10px"><summary class="btn btn-s btn-sm" style="list-style:none">+ Pedido de adesão (carona)</summary>
                    <form method="POST" action="{{ route('atas.adesao', [$ata, $ai]) }}" class="form-grid" style="margin-top:12px">@csrf
                        <x-campo nome="aderente_nome" rotulo="Órgão aderente" obrigatorio /><x-campo nome="aderente_cnpj" rotulo="CNPJ do aderente" obrigatorio /><x-campo nome="quantidade" rotulo="Quantidade" obrigatorio />
                        <div class="campo full"><label for="{{ $_rot = 'c'.\Illuminate\Support\Str::random(8) }}">Justificativa da adesão</label><textarea id="{{ $_rot }}" name="justificativa" class="input" rows="2" required></textarea></div>
                        <div class="campo full"><label for="{{ $_rot = 'c'.\Illuminate\Support\Str::random(8) }}">Vantagem e compatibilidade dos preços com o mercado (art. 86, § 2º)</label><textarea id="{{ $_rot }}" name="vantagem" class="input" rows="2" required></textarea></div>
                        <div class="form-acoes full"><button class="btn btn-p" type="submit">Registrar pedido</button></div></form>
                </details>
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
