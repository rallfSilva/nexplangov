<x-layouts.app titulo="Nova demanda">
    <div>
        <a href="{{ route('demandas.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Demandas</a>
        <h1 class="h1" style="margin-top:8px">Nova demanda</h1>
        <p class="sub">Descreva o que a sua área precisa e por quê. Isso fundamenta todo o processo.</p>
    </div>
    @foreach ($exercicios as $ex)
        @if ($p = $prazos->get($ex))
            @php($fechado = \App\Licitacao\Regras\PrazoDemanda::encerrado($p->data_limite, today()))
            <div class="flash {{ $fechado ? 'flash-aviso' : 'flash-ok' }}" role="status">Demandas para o PCA {{ $ex }}: {{ $fechado ? 'prazo encerrado em' : 'envie até' }} {{ $p->data_limite->format('d/m/Y') }}.@if ($fechado) Agora só entram as exceções justificadas.@endif <span class="lei">art. 12, VII</span></div>
        @endif
    @endforeach
    <form method="POST" action="{{ route('demandas.store') }}" class="card" novalidate>
        @csrf
        <div class="form-grid">
            <x-campo nome="exercicio" rotulo="Exercício do PCA" obrigatorio ajuda="Ano em que a contratação deve acontecer.">
                <select id="campo-exercicio" name="exercicio" class="input">
                    @foreach ($exercicios as $ex)<option value="{{ $ex }}" @selected((int) old('exercicio', $exercicioPadrao) === $ex)>{{ $ex }}</option>@endforeach
                </select>
            </x-campo>
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
            <x-campo nome="quantidade" rotulo="Quantidade estimada" inputmode="decimal" obrigatorio />
            <x-campo nome="valor_estimado" rotulo="Valor estimado preliminar (R$)" inputmode="decimal" obrigatorio />
            <x-campo nome="data_pretendida" rotulo="Para quando" tipo="date" obrigatorio />
            <x-campo nome="prioridade" rotulo="Prioridade" obrigatorio>
                <select id="campo-prioridade" name="prioridade" class="input">
                    @foreach (\App\Models\ItemPca::PRIORIDADES as $v => $r)<option value="{{ $v }}" @selected((int) old('prioridade', 2) === $v)>{{ $r }}</option>@endforeach
                </select>
            </x-campo>
            <x-campo nome="vinculadas" rotulo="Demandas vinculadas" classe="full" ajuda="Indique demandas que dependem desta ou a complementam (ex.: equipamento e instalação). Segure Ctrl para escolher mais de uma.">
                <select id="campo-vinculadas" name="vinculadas[]" class="input" multiple size="4">
                    @foreach ($outrasDemandas as $o)<option value="{{ $o->id }}" @selected(in_array($o->id, array_map('intval', (array) old('vinculadas', [])), true))>{{ $o->numero }}/{{ $o->ano }} · {{ \Illuminate\Support\Str::limit($o->objeto, 80) }}</option>@endforeach
                </select>
            </x-campo>
            <x-campo nome="excecao_prazo" rotulo="Exceção ao prazo de envio" ajuda="Só depois da data-limite do exercício.">
                <select id="campo-excecao_prazo" name="excecao_prazo" class="input">
                    <option value="">Nenhuma</option>
                    @foreach (\App\Licitacao\Regras\PrazoDemanda::EXCECOES as $v => $r)<option value="{{ $v }}" @selected(old('excecao_prazo') === $v)>{{ $r }}</option>@endforeach
                </select>
            </x-campo>
            <x-campo nome="justificativa_excecao" rotulo="Justificativa da exceção" classe="full">
                <textarea id="campo-justificativa_excecao" name="justificativa_excecao" class="input" rows="2">{{ old('justificativa_excecao') }}</textarea>
            </x-campo>
        </div>
        <div class="form-acoes"><a href="{{ route('demandas.index') }}" class="btn btn-s">Cancelar</a><button class="btn btn-p" type="submit"><x-icone nome="enviar" />Enviar ao planejamento</button></div>
    </form>
</x-layouts.app>
