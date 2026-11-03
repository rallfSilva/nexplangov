{{-- Formulário de liquidação: $acao (URL), $p (prefixo dos ids), $categoria (sugerida), $valor (máximo), $fontes --}}
<form method="POST" action="{{ $acao }}" class="form-grid" style="margin-top:8px">@csrf
    <div class="campo"><label for="{{ $p }}-cat">Categoria (art. 141)</label><select id="{{ $p }}-cat" name="categoria" class="input">@foreach (\App\Licitacao\Regras\OrdemCronologica::CATEGORIAS as $v => $r)<option value="{{ $v }}" @selected($categoria === $v)>{{ $r }}</option>@endforeach</select></div>
    <div class="campo"><label for="{{ $p }}-fonte">Fonte de recursos</label><input id="{{ $p }}-fonte" name="fonte_recurso" class="input" list="{{ $p }}-fontes" placeholder="Ex.: 1500 · Recursos livres" required>
        <datalist id="{{ $p }}-fontes">@foreach ($fontes as $f)<option value="{{ $f }}">@endforeach</datalist></div>
    <div class="campo"><label for="{{ $p }}-nf">Nota fiscal</label><input id="{{ $p }}-nf" name="nota_fiscal" class="input" required></div>
    <div class="campo"><label for="{{ $p }}-nfd">Emitida em</label><input id="{{ $p }}-nfd" type="date" name="nota_emitida_em" class="input" required></div>
    <div class="campo"><label for="{{ $p }}-vb">Valor bruto (até {{ reais($valor) }})</label><input id="{{ $p }}-vb" name="valor_bruto" class="input" inputmode="decimal" value="{{ number_format((float) $valor, 2, ',', '.') }}" required></div>
    <div class="campo"><label for="{{ $p }}-ret">Retenções (tributos)</label><input id="{{ $p }}-ret" name="retencoes" class="input" inputmode="decimal" value="0,00"></div>
    <div class="campo full"><label for="{{ $p }}-at">Atesto: o objeto foi entregue ou executado conforme o contrato</label><textarea id="{{ $p }}-at" name="atesto" class="input" rows="2" required></textarea></div>
    <div class="form-acoes full"><button class="btn btn-p btn-sm" type="submit">Liquidar e pôr na fila</button></div>
</form>
