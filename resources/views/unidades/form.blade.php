<x-layouts.app :titulo="$unidade->exists ? 'Editar unidade' : 'Nova unidade'">
    <div>
        <a href="{{ route('unidades.index', ['orgao' => $orgaoId]) }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Unidades</a>
        <h1 class="h1" style="margin-top:8px">{{ $unidade->exists ? 'Editar '.$unidade->sigla : 'Nova unidade' }}</h1>
    </div>

    @if ($orgaos->isNotEmpty())
        <form method="GET" class="card filtros">
            <div class="campo">
                <label for="f-orgao">Órgão da nova unidade</label>
                <select id="f-orgao" name="orgao" class="input" onchange="this.form.submit()">
                    <option value="">Escolha o órgão</option>
                    @foreach ($orgaos as $o)
                        <option value="{{ $o->id }}" @selected($o->id === $orgaoId)>{{ $o->sigla }} · {{ $o->nome }}</option>
                    @endforeach
                </select>
                <span class="ajuda">A lista de unidades superiores e de chefes muda conforme o órgão.</span>
            </div>
        </form>
    @endif

    @if ($orgaoId)
        <form method="POST" action="{{ $unidade->exists ? route('unidades.update', $unidade) : route('unidades.store') }}" class="card" novalidate>
            @csrf
            @if ($unidade->exists) @method('PUT') @else <input type="hidden" name="orgao_id" value="{{ $orgaoId }}"> @endif
            <div class="form-grid">
                <x-campo nome="sigla" rotulo="Sigla" :valor="$unidade->sigla" obrigatorio maxlength="30" ajuda="Única dentro do órgão. Ex.: DTI" />
                <x-campo nome="nome" rotulo="Nome da unidade" :valor="$unidade->nome" obrigatorio />
                <x-campo nome="tipo" rotulo="Tipo" obrigatorio>
                    <select id="campo-tipo" name="tipo" class="input">
                        @foreach (\App\Models\Unidade::TIPOS as $valor => $rotulo)
                            <option value="{{ $valor }}" @selected(old('tipo', $unidade->tipo) === $valor)>{{ $rotulo }}</option>
                        @endforeach
                    </select>
                </x-campo>
                <x-campo nome="parent_id" rotulo="Unidade superior">
                    <select id="campo-parent_id" name="parent_id" class="input">
                        <option value="">Nenhuma (topo do organograma)</option>
                        @foreach ($superiores as $s)
                            <option value="{{ $s->id }}" @selected((int) old('parent_id', $unidade->parent_id) === $s->id)>{{ $s->sigla }} · {{ $s->nome }}</option>
                        @endforeach
                    </select>
                </x-campo>
                <x-campo nome="chefe_user_id" rotulo="Chefe da unidade" ajuda="Só aparecem usuários ativos do órgão.">
                    <select id="campo-chefe_user_id" name="chefe_user_id" class="input">
                        <option value="">Ainda não definido</option>
                        @foreach ($chefes as $c)
                            <option value="{{ $c->id }}" @selected((int) old('chefe_user_id', $unidade->chefe_user_id) === $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </x-campo>
                <x-campo nome="codigo_unidade_pncp" rotulo="Código da unidade compradora no PNCP" :valor="$unidade->codigo_unidade_pncp" maxlength="30" ajuda="Usado no envio de compras, atas e contratos ao PNCP." />
                <x-campo nome="email" rotulo="E-mail da unidade" tipo="email" :valor="$unidade->email" />
                <x-campo nome="telefone" rotulo="Telefone" :valor="$unidade->telefone" placeholder="(95) 0000-0000" />
                <div class="campo full">
                    <label class="check"><input type="checkbox" name="ativo" value="1" @checked(old('ativo', $unidade->ativo))> Unidade ativa</label>
                </div>
            </div>
            <div class="form-acoes">
                @if ($unidade->exists)
                    <button type="submit" form="excluir-unidade" class="btn btn-d" style="margin-right:auto"><x-icone nome="lixeira" tamanho="16" />Excluir</button>
                @endif
                <a href="{{ route('unidades.index', ['orgao' => $orgaoId]) }}" class="btn btn-s">Cancelar</a>
                <button type="submit" class="btn btn-p">Salvar</button>
            </div>
        </form>
        @if ($unidade->exists)
            <form id="excluir-unidade" method="POST" action="{{ route('unidades.destroy', $unidade) }}" data-confirmar="Excluir a unidade {{ $unidade->sigla }}? A exclusão fica registrada na auditoria." onsubmit="return confirm(this.dataset.confirmar)">
                @csrf @method('DELETE')
            </form>
        @endif
    @endif
</x-layouts.app>
