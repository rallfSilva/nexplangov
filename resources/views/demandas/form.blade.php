<x-layouts.app titulo="Nova demanda">
    <div>
        <a href="{{ route('demandas.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Demandas</a>
        <h1 class="h1" style="margin-top:8px">Nova demanda</h1>
        <p class="sub">Descreva o que a sua área precisa e por quê. Isso fundamenta todo o processo.</p>
    </div>
    <form method="POST" action="{{ route('demandas.store') }}" class="card" novalidate>
        @csrf
        <div class="form-grid">
            <x-campo nome="unidade_id" rotulo="Unidade requisitante" obrigatorio>
                <select id="campo-unidade_id" name="unidade_id" class="input">
                    <option value="">Escolha</option>
                    @foreach ($unidades as $u)<option value="{{ $u->id }}" @selected((int) old('unidade_id') === $u->id)>{{ $u->sigla }} · {{ $u->nome }}</option>@endforeach
                </select>
            </x-campo>
            <x-campo nome="item_pca_id" rotulo="Item do PCA" ajuda="Se a demanda não estiver no plano, a justificativa precisa dizer por quê.">
                <select id="campo-item_pca_id" name="item_pca_id" class="input">
                    <option value="">Não está no PCA</option>
                    @foreach ($itensPca as $i)<option value="{{ $i->id }}" @selected((int) old('item_pca_id') === $i->id)>{{ $i->numero }} · {{ \Illuminate\Support\Str::limit($i->descricao, 70) }}</option>@endforeach
                </select>
            </x-campo>
            <x-campo nome="objeto" rotulo="O que precisa ser contratado" obrigatorio classe="full" maxlength="1000" />
            <x-campo nome="justificativa" rotulo="Justificativa da necessidade" obrigatorio classe="full">
                <textarea id="campo-justificativa" name="justificativa" class="input" rows="5">{{ old('justificativa') }}</textarea>
            </x-campo>
            <x-campo nome="quantidade" rotulo="Quantidade estimada" inputmode="decimal" />
            <x-campo nome="valor_estimado" rotulo="Valor estimado (R$)" inputmode="decimal" />
            <x-campo nome="data_pretendida" rotulo="Para quando" tipo="date" />
            <x-campo nome="prioridade" rotulo="Prioridade" obrigatorio>
                <select id="campo-prioridade" name="prioridade" class="input">
                    @foreach (\App\Models\ItemPca::PRIORIDADES as $v => $r)<option value="{{ $v }}" @selected((int) old('prioridade', 2) === $v)>{{ $r }}</option>@endforeach
                </select>
            </x-campo>
        </div>
        <div class="form-acoes"><a href="{{ route('demandas.index') }}" class="btn btn-s">Cancelar</a><button class="btn btn-p" type="submit"><x-icone nome="enviar" />Enviar ao planejamento</button></div>
    </form>
</x-layouts.app>
