@props(['nome', 'rotulo', 'tipo' => 'text', 'valor' => null, 'ajuda' => null, 'obrigatorio' => false, 'classe' => ''])
@php($erro = $errors->first($nome))
<div class="campo {{ $classe }}">
    <label for="campo-{{ $nome }}">{{ $rotulo }}@if ($obrigatorio) <span aria-hidden="true" style="color:#9F1D1D">*</span>@endif</label>
    @if ($slot->isNotEmpty())
        {{ $slot }}
    @else
        <input id="campo-{{ $nome }}" name="{{ $nome }}" type="{{ $tipo }}" value="{{ old($nome, $valor) }}" class="input"
            @if ($obrigatorio) required @endif
            @if ($erro) aria-invalid="true" aria-describedby="erro-{{ $nome }}" @endif
            {{ $attributes }}>
    @endif
    @if ($ajuda)<span class="ajuda">{{ $ajuda }}</span>@endif
    @if ($erro)<span class="erro" id="erro-{{ $nome }}">{{ $erro }}</span>@endif
</div>
