{{-- Edição do item do processo na fase preparatória (quantidade, unidade, descrição, catálogo e benefício ME/EPP). --}}
@php
    $chave = "editar-item-{$item->id}";
    $doEnvio = old('form_item') === $chave;
    $valor = fn (string $campo, $atual) => $doEnvio ? old($campo) : $atual;
    $pfx = "i-{$item->id}";
    // Sem separador de milhar: "3.200" seria lido como 3,2 por decimal_br().
    $qtd = (float) $item->quantidade;
@endphp
<form method="POST" action="{{ route('processos.itens.update', [$processo, $item]) }}" class="form-grid linha-edicao" style="margin-top:14px" novalidate>
    @csrf @method('PUT')
    <input type="hidden" name="form_item" value="{{ $chave }}">
    <div class="full"><b>Editar item {{ $item->numero }}</b> <span class="small muted">· as cotações ficam; o total é recalculado com a nova quantidade</span></div>
    @if ($doEnvio && $errors->any())
        <div class="flash flash-erro full" role="alert"><x-icone nome="alerta" style="flex:none" /><div>Confira os campos:<ul style="margin:8px 0 0;padding-left:18px">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div></div>
    @endif
    <div class="campo full"><label for="{{ $pfx }}-desc">Descrição</label><input id="{{ $pfx }}-desc" name="descricao" class="input" required value="{{ $valor('descricao', $item->descricao) }}"></div>
    <div class="campo"><label for="{{ $pfx }}-tipo">Tipo</label><select id="{{ $pfx }}-tipo" name="material_servico" class="input">@foreach (['M' => 'Material', 'S' => 'Serviço'] as $v => $rot)<option value="{{ $v }}" @selected($valor('material_servico', $item->material_servico) === $v)>{{ $rot }}</option>@endforeach</select></div>
    <div class="campo"><label for="{{ $pfx }}-cat">CATMAT/CATSER</label><input id="{{ $pfx }}-cat" name="codigo_catalogo" class="input" inputmode="numeric" value="{{ $valor('codigo_catalogo', $item->codigo_catalogo) }}"></div>
    <div class="campo"><label for="{{ $pfx }}-un">Unidade</label><input id="{{ $pfx }}-un" name="unidade_medida" class="input" required value="{{ $valor('unidade_medida', $item->unidade_medida) }}"></div>
    <div class="campo"><label for="{{ $pfx }}-qtd">Quantidade</label><input id="{{ $pfx }}-qtd" name="quantidade" class="input" inputmode="decimal" required value="{{ $valor('quantidade', number_format($qtd, floor($qtd) == $qtd ? 0 : 4, ',', '')) }}"></div>
    <div class="campo"><label for="{{ $pfx }}-ben">Benefício ME/EPP</label><select id="{{ $pfx }}-ben" name="tipo_beneficio" class="input">@foreach (\App\Models\ItemProcesso::BENEFICIOS as $v => $rot)<option value="{{ $v }}" @selected((int) $valor('tipo_beneficio', $item->tipo_beneficio) === $v)>{{ $rot }}</option>@endforeach</select></div>
    <div class="campo" style="justify-content:flex-end">
        <div class="btns">
            <a class="btn btn-s" href="{{ route('processos.show', [$processo, 'aba' => 'itens']) }}#item-{{ $item->id }}">Cancelar</a>
            <button class="btn btn-p" type="submit">Salvar alterações</button>
        </div>
    </div>
</form>
