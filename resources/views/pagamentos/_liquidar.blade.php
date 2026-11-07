{{-- Formulário de liquidação: $acao (URL), $p (prefixo dos ids), $categoria (sugerida), $valor (máximo), $fontes, $empenhos (do credor, com saldo) --}}
<form method="POST" action="{{ $acao }}" class="form-grid" style="margin-top:8px">@csrf
    <div class="campo full"><label for="{{ $p }}-ne">Nota de empenho <span class="lei">Lei 4.320, art. 60</span></label><select id="{{ $p }}-ne" name="empenho_id" class="input" @required(config('nexplangov.empenho_obrigatorio'))>
        <option value="">{{ ($empenhos ?? collect())->isEmpty() ? 'Nenhum empenho com saldo: registre o empenho antes' : 'Selecione' }}</option>
        @foreach ($empenhos ?? [] as $e)@php($s = $e->saldo())@if ($s > 0)<option value="{{ $e->id }}" data-fonte="{{ $e->fonte_recurso }}">{{ $e->identificacao() }} · {{ $e->fonte_recurso }} · saldo {{ reais($s) }}</option>@endif @endforeach
    </select><div class="small muted">A fonte de recursos da liquidação é a do empenho.</div></div>
    <div class="campo"><label for="{{ $p }}-cat">Categoria (art. 141)</label><select id="{{ $p }}-cat" name="categoria" class="input">@foreach (\App\Licitacao\Regras\OrdemCronologica::CATEGORIAS as $v => $r)<option value="{{ $v }}" @selected($categoria === $v)>{{ $r }}</option>@endforeach</select></div>
    <div class="campo"><label for="{{ $p }}-fonte">Fonte de recursos</label><input id="{{ $p }}-fonte" name="fonte_recurso" class="input" list="{{ $p }}-fontes" placeholder="Ex.: 1500 · Recursos livres" required>
        <datalist id="{{ $p }}-fontes">@foreach ($fontes as $f)<option value="{{ $f }}">@endforeach</datalist></div>
    <div class="campo"><label for="{{ $p }}-pc">Pedido de compra ou autorização de fornecimento</label><input id="{{ $p }}-pc" name="pedido_compra" class="input" maxlength="60" placeholder="Opcional"></div>
    <div class="campo"><label for="{{ $p }}-nf">Nota fiscal</label><input id="{{ $p }}-nf" name="nota_fiscal" class="input" required></div>
    <div class="campo"><label for="{{ $p }}-nfd">Emitida em</label><input id="{{ $p }}-nfd" type="date" name="nota_emitida_em" class="input" required></div>
    <div class="campo"><label for="{{ $p }}-vb">Valor bruto (até {{ reais($valor) }})</label><input id="{{ $p }}-vb" name="valor_bruto" class="input" inputmode="decimal" value="{{ number_format((float) $valor, 2, ',', '.') }}" required></div>
    <div class="campo"><label for="{{ $p }}-ret">Retenções (tributos)</label><input id="{{ $p }}-ret" name="retencoes" class="input" inputmode="decimal" value="0,00"></div>
    <div class="campo full"><label for="{{ $p }}-at">Atesto: o objeto foi entregue ou executado conforme o contrato</label><textarea id="{{ $p }}-at" name="atesto" class="input" rows="2" required></textarea></div>
    <div class="form-acoes full"><button class="btn btn-p btn-sm" type="submit">Liquidar e pôr na fila</button></div>
</form>
