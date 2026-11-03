{{-- Formulário da cotação (art. 23, § 1º), usado para registrar e para corrigir. Com $cotacao, edita. --}}
@php
    $edicao = isset($cotacao);
    $chave = $edicao ? "editar-{$cotacao->id}" : "nova-{$item->id}";
    $pfx = $edicao ? "e-{$cotacao->id}" : "f-{$item->id}";
    $doEnvio = old('form_cotacao') === $chave;
    $valor = fn (string $campo, $padrao = null) => $doEnvio ? old($campo) : $padrao;
    $fonteAtual = $valor('fonte', $edicao ? $cotacao->fonte : null);
@endphp
<form method="POST" action="{{ $edicao ? route('processos.cotacoes.update', [$processo, $cotacao]) : route('processos.cotacoes.store', [$processo, $item]) }}" class="form-grid" style="margin-top:14px" novalidate>
    @csrf
    @if ($edicao) @method('PUT') @endif
    <input type="hidden" name="form_cotacao" value="{{ $chave }}">
    @if ($doEnvio && $errors->any())
        <div class="flash flash-erro full" role="alert"><x-icone nome="alerta" style="flex:none" /><div>Confira os campos:<ul style="margin:8px 0 0;padding-left:18px">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div></div>
    @endif
    <div class="campo"><label for="{{ $pfx }}-fonte">Fonte <span class="lei">art. 23, § 1º</span></label><select id="{{ $pfx }}-fonte" name="fonte" class="input">@foreach (\App\Licitacao\Enums\FontePreco::opcoes() as $v => $rot)<option value="{{ $v }}" @selected($fonteAtual === $v)>{{ $rot }}</option>@endforeach</select></div>
    <div class="campo"><label for="{{ $pfx }}-ref">Referência</label><input id="{{ $pfx }}-ref" name="referencia" class="input" required placeholder="Fornecedor, órgão ou sítio" value="{{ $valor('referencia', $edicao ? $cotacao->referencia : '') }}"></div>
    <div class="campo"><label for="{{ $pfx }}-doc">CNPJ (cotação direta)</label><input id="{{ $pfx }}-doc" name="documento_fonte" class="input" placeholder="00.000.000/0000-00" value="{{ $valor('documento_fonte', $edicao ? $cotacao->documento_fonte : '') }}"></div>
    <div class="campo"><label for="{{ $pfx }}-val">Valor unitário (R$)</label><input id="{{ $pfx }}-val" name="valor_unitario" class="input" inputmode="decimal" required value="{{ $valor('valor_unitario', $edicao ? numero_br($cotacao->valor_unitario, round((float) $cotacao->valor_unitario, 2) == (float) $cotacao->valor_unitario ? 2 : 4) : '') }}"></div>
    <div class="campo"><label for="{{ $pfx }}-data">Data</label><input id="{{ $pfx }}-data" name="data_cotacao" type="date" class="input" required value="{{ $valor('data_cotacao', $edicao ? $cotacao->data_cotacao->format('Y-m-d') : today()->format('Y-m-d')) }}"></div>
    <div class="campo" style="justify-content:flex-end">
        <div class="btns">
            @if ($edicao)<a class="btn btn-s" href="{{ route('processos.show', [$processo, 'aba' => 'itens']) }}#item-{{ $item->id }}">Cancelar</a>@endif
            <button class="btn btn-p" type="submit">{{ $edicao ? 'Salvar alterações' : 'Registrar' }}</button>
        </div>
    </div>
</form>
