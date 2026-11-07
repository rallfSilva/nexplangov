@php($titulo = $item->exists ? 'Editar item do catálogo' : 'Novo item do catálogo')
@php($atributos = old('atributos', $item->atributos ?: []))
@php($atributos = array_pad(array_values($atributos), max(count($atributos) + 2, 4), ['nome' => '', 'valor' => '']))
<x-layouts.app :titulo="$titulo">
    <div>
        <a href="{{ route('catalogo.orgao.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Catálogo do órgão</a>
        <h1 class="h1" style="margin-top:8px">{{ $titulo }}</h1>
        <p class="sub">Especificação do produto com qualidade, rendimento, durabilidade e segurança <span class="lei">art. 40, § 1º, I</span>. Termos vagos como "diversos" ou "em geral" não são aceitos.</p>
    </div>
    <form method="POST" action="{{ $item->exists ? route('catalogo.orgao.update', $item) : route('catalogo.orgao.store') }}" class="card" enctype="multipart/form-data" novalidate>
        @csrf @if ($item->exists) @method('PUT') @endif
        <div class="form-grid">
            <x-campo nome="tipo" rotulo="Tipo" obrigatorio><select id="campo-tipo" name="tipo" class="input">@foreach (\App\Models\ItemCatalogoOrgao::TIPOS as $v => $r)<option value="{{ $v }}" @selected(old('tipo', $item->tipo) === $v)>{{ $r }}</option>@endforeach</select></x-campo>
            <x-campo nome="codigo_catalogo" rotulo="Código CATMAT/CATSER" :valor="$item->codigo_catalogo" ajuda="Conferido no catálogo nacional quando ele está carregado." maxlength="20" />
            <x-campo nome="descricao" rotulo="Descrição padronizada" :valor="$item->descricao" obrigatorio classe="full" maxlength="1000" ajuda="O que é, material ou característica principal e medida. Ex.: Papel sulfite A4 branco 75 g/m², resma com 500 folhas." />
            <x-campo nome="unidade_fornecimento" rotulo="Unidade de fornecimento" :valor="$item->unidade_fornecimento" obrigatorio maxlength="40" ajuda="Ex.: resma, unidade, caixa com 12, hora" />
            <x-campo nome="natureza_despesa" rotulo="Natureza da despesa" :valor="$item->natureza_despesa" maxlength="20" ajuda="Ex.: 3.3.90.30" />
            <x-campo nome="especificacao" rotulo="Especificação complementar" classe="full"><textarea id="campo-especificacao" name="especificacao" class="input" rows="3" maxlength="5000">{{ old('especificacao', $item->especificacao) }}</textarea></x-campo>
        </div>
        <fieldset style="border:0;padding:0;margin:18px 0 0">
            <legend class="h2" style="font-size:16px">Atributos</legend>
            <p class="hint">Material precisa de ao menos um atributo. Ex.: Gramatura · 75 g/m²; Cor · branca.</p>
            @error('atributos')<span class="erro">{{ $message }}</span>@enderror
            <div class="form-grid" style="margin-top:8px">
                @foreach ($atributos as $k => $a)
                    <div class="campo"><label for="attr-nome-{{ $k }}">Atributo {{ $k + 1 }}</label><input id="attr-nome-{{ $k }}" name="atributos[{{ $k }}][nome]" class="input" value="{{ $a['nome'] ?? '' }}" maxlength="80"></div>
                    <div class="campo"><label for="attr-valor-{{ $k }}">Valor do atributo {{ $k + 1 }}</label><input id="attr-valor-{{ $k }}" name="atributos[{{ $k }}][valor]" class="input" value="{{ $a['valor'] ?? '' }}" maxlength="200"></div>
                @endforeach
            </div>
        </fieldset>
        <div class="form-grid" style="margin-top:18px">
            <x-campo nome="imagem" rotulo="Imagem do item" classe="full" ajuda="PNG, JPG ou WEBP de até 2 MB.">
                <input id="campo-imagem" name="imagem" type="file" class="input" accept="image/png,image/jpeg,image/webp">
            </x-campo>
            @if ($item->imagem_caminho)<div class="campo full"><img src="{{ route('catalogo.orgao.imagem', $item) }}" alt="Imagem atual de {{ $item->descricao }}" width="120" style="border-radius:10px"></div>@endif
            @if ($errors->has('descricao') && str_contains($errors->first('descricao'), 'parecido'))
                <div class="campo full"><label class="check"><input type="checkbox" name="confirmo_diferente" value="1"> Conferi os itens parecidos e este é um item diferente</label></div>
            @endif
        </div>
        <div class="form-acoes"><a href="{{ route('catalogo.orgao.index') }}" class="btn btn-s">Voltar</a><button class="btn btn-p" type="submit">Salvar</button></div>
    </form>
</x-layouts.app>
