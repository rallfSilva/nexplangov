<x-layouts.auth titulo="Ata {{ $ata->identificacao() }}">
    <a href="{{ route('carona.index') }}" class="small" style="font-weight:600">← Atas vigentes</a>
    <h1 class="h1" style="margin-top:6px">Ata {{ $ata->identificacao() }}</h1>
    <p class="sub">{{ $ata->orgao->nome }} ({{ \App\Models\AtaAdesao::ESFERAS[$ata->orgao->esfera] ?? $ata->orgao->esfera }}) · vigente até {{ $ata->vigencia_fim->format('d/m/Y') }}</p>
    <x-flash />
    <dl class="dl">
        <div><dt>Objeto</dt><dd>{{ $ata->processo->objeto }}</dd></div>
        <div><dt>Fornecedor</dt><dd>{{ $ata->fornecedor->razao_social }}</dd></div>
    </dl>

    <h2 style="margin-top:14px">Itens</h2>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Item</th><th class="num">Preço unitário</th><th class="num">Máximo por órgão</th><th class="num">Ainda disponível</th></tr></thead><tbody>
        @foreach ($ata->itens as $ai)
            @php($disponivel = max(0, (float) $ai->quantidade_registrada * 2 - $ai->autorizadoEmAdesoes()))
            <tr><td><b>{{ $ai->item->numero }}</b> {{ \Illuminate\Support\Str::limit($ai->item->descricao, 80) }} <span class="muted small">({{ $ai->item->unidade_medida }})</span></td>
                <td class="num valor">{{ reais($ai->valor_unitario, 4) }}</td>
                <td class="num">{{ numero_br((float) $ai->quantidade_registrada * 0.5, 2) }}</td>
                <td class="num">{{ numero_br($disponivel, 2) }}</td></tr>
        @endforeach
    </tbody></table></div>
    <p class="small muted">Cada órgão pode pedir até 50% do registrado em cada item; as adesões somadas não passam do dobro. <span class="lei">art. 86, §§ 4º e 5º</span></p>

    <h2 style="margin-top:14px">Pedir adesão</h2>
    <form method="POST" action="{{ route('carona.pedir', $ata) }}" enctype="multipart/form-data" class="form-grid">@csrf
        <div style="position:absolute;left:-10000px" aria-hidden="true"><label for="campo-site">Site</label><input id="campo-site" name="site" tabindex="-1" autocomplete="off"></div>
        <x-campo nome="item_id" rotulo="Item" obrigatorio classe="full">
            <select id="campo-item_id" name="item_id" class="input" required>
                @foreach ($ata->itens as $ai)<option value="{{ $ai->id }}" @selected(old('item_id') == $ai->id)>Item {{ $ai->item->numero }}: {{ \Illuminate\Support\Str::limit($ai->item->descricao, 60) }}</option>@endforeach
            </select>
        </x-campo>
        <x-campo nome="aderente_nome" rotulo="Órgão ou entidade que pede a adesão" obrigatorio classe="full" />
        <x-campo nome="aderente_cnpj" rotulo="CNPJ do órgão" obrigatorio inputmode="numeric" />
        <x-campo nome="aderente_esfera" rotulo="Esfera" obrigatorio>
            <select id="campo-aderente_esfera" name="aderente_esfera" class="input" required>
                @foreach (\App\Models\AtaAdesao::ESFERAS as $v => $r)<option value="{{ $v }}" @selected(old('aderente_esfera', 'municipal') === $v)>{{ $r }}</option>@endforeach
            </select>
        </x-campo>
        <x-campo nome="aderente_uf" rotulo="UF" obrigatorio maxlength="2" />
        <x-campo nome="aderente_municipio" rotulo="Município" />
        <x-campo nome="solicitante_nome" rotulo="Servidor responsável pelo pedido" obrigatorio />
        <x-campo nome="solicitante_cargo" rotulo="Cargo" obrigatorio />
        <x-campo nome="solicitante_email" rotulo="E-mail institucional" tipo="email" obrigatorio />
        <x-campo nome="quantidade" rotulo="Quantidade" obrigatorio inputmode="decimal" />
        <x-campo nome="justificativa" rotulo="Justificativa da adesão (necessidade do órgão)" obrigatorio classe="full">
            <textarea id="campo-justificativa" name="justificativa" class="input" rows="3" required>{{ old('justificativa') }}</textarea>
        </x-campo>
        <x-campo nome="vantagem" rotulo="Vantagem e compatibilidade dos preços com o mercado, com a pesquisa feita (art. 86, § 2º, I e II; art. 23)" obrigatorio classe="full">
            <textarea id="campo-vantagem" name="vantagem" class="input" rows="3" required>{{ old('vantagem') }}</textarea>
        </x-campo>
        <x-campo nome="oficio" rotulo="Ofício do órgão, assinado pela autoridade (PDF, até 10 MB)" tipo="file" obrigatorio classe="full" accept="application/pdf" />
        <p class="small muted full">Os dados do servidor são usados só para responder a este pedido (LGPD, art. 7º, III). <a href="{{ route('privacidade') }}">Aviso de privacidade</a>.</p>
        <div class="form-acoes full"><button class="btn btn-p" type="submit">Enviar pedido</button></div>
    </form>
</x-layouts.auth>
