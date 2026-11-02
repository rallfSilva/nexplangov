<x-layouts.app titulo="Novo contrato">
    <div>
        <a href="{{ route('contratos.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Contratos</a>
        <h1 class="h1" style="margin-top:8px">Novo contrato</h1>
    </div>
    <div class="aviso-lei">Gestor e fiscal são pessoas diferentes (art. 7º, § 1º, e art. 117). Serviço contínuo vai até 5 anos e prorroga até 10 (arts. 106 e 107). Garantia até 5%, ou 10% com justificativa (art. 98).</div>
    <form method="POST" action="{{ route('contratos.store') }}" class="card" novalidate>
        @csrf
        <div class="form-grid">
            <x-campo nome="numero" rotulo="Número" obrigatorio />
            <x-campo nome="ano" rotulo="Ano" tipo="number" :valor="$contrato->ano" obrigatorio />
            <x-campo nome="tipo" rotulo="Instrumento" obrigatorio><select id="campo-tipo" name="tipo" class="input">@foreach (\App\Models\Contrato::TIPOS as $v => $r)<option value="{{ $v }}" @selected((int) old('tipo', $contrato->tipo) === $v)>{{ $r }}</option>@endforeach</select></x-campo>
            <x-campo nome="processo_id" rotulo="Processo de origem"><select id="campo-processo_id" name="processo_id" class="input"><option value="">Sem processo no sistema</option>@foreach ($processos as $p)<option value="{{ $p->id }}" @selected((int) old('processo_id', $contrato->processo_id) === $p->id)>{{ $p->identificacao() }} · {{ \Illuminate\Support\Str::limit($p->objeto, 50) }}</option>@endforeach</select></x-campo>
            <x-campo nome="objeto" rotulo="Objeto" obrigatorio classe="full"><textarea id="campo-objeto" name="objeto" class="input" rows="3">{{ old('objeto', $contrato->objeto) }}</textarea></x-campo>
            <x-campo nome="fornecedor_id" rotulo="Fornecedor" obrigatorio><select id="campo-fornecedor_id" name="fornecedor_id" class="input"><option value="">Escolha</option>@foreach ($fornecedores as $f)<option value="{{ $f->id }}" @selected((int) old('fornecedor_id', $contrato->fornecedor_id) === $f->id)>{{ $f->razao_social }} · {{ $f->documento }}</option>@endforeach</select></x-campo>
            <x-campo nome="categoria" rotulo="Categoria" obrigatorio><select id="campo-categoria" name="categoria" class="input">@foreach (\App\Licitacao\Enums\CategoriaProcesso::opcoes() as $v => $r)<option value="{{ $v }}" @selected((int) old('categoria', $contrato->categoria) === $v)>{{ $r }}</option>@endforeach</select></x-campo>
            <x-campo nome="gestor_id" rotulo="Gestor do contrato" obrigatorio><select id="campo-gestor_id" name="gestor_id" class="input"><option value="">Escolha</option>@foreach ($usuarios as $u)<option value="{{ $u->id }}" @selected((int) old('gestor_id') === $u->id)>{{ $u->name }}</option>@endforeach</select></x-campo>
            <x-campo nome="fiscal_id" rotulo="Fiscal do contrato" obrigatorio><select id="campo-fiscal_id" name="fiscal_id" class="input"><option value="">Escolha</option>@foreach ($usuarios as $u)<option value="{{ $u->id }}" @selected((int) old('fiscal_id') === $u->id)>{{ $u->name }}</option>@endforeach</select></x-campo>
            <x-campo nome="valor_inicial" rotulo="Valor inicial (R$)" :valor="$contrato->valor_inicial ? numero_br($contrato->valor_inicial) : null" obrigatorio inputmode="decimal" />
            <x-campo nome="assinatura" rotulo="Assinatura" tipo="date" obrigatorio />
            <x-campo nome="vigencia_inicio" rotulo="Início da vigência" tipo="date" obrigatorio />
            <x-campo nome="vigencia_fim" rotulo="Fim da vigência" tipo="date" obrigatorio />
            <x-campo nome="garantia_percentual" rotulo="Garantia (% do valor)" inputmode="decimal" />
            <x-campo nome="justificativa_garantia" rotulo="Justificativa da garantia acima de 5%" />
            <x-campo nome="fundamento_vigencia_especial" rotulo="Fundamento de prazo especial" ajuda="Só para prazos dos arts. 108 a 110." />
            <div class="campo full" style="flex-direction:row;flex-wrap:wrap;gap:8px 24px">
                <label class="check"><input type="checkbox" name="servico_continuo" value="1" @checked(old('servico_continuo'))> Serviço ou fornecimento contínuo</label>
                <label class="check"><input type="checkbox" name="reforma_equipamento" value="1" @checked(old('reforma_equipamento'))> Reforma de edifício ou equipamento (acréscimo até 50%)</label>
                <label class="check"><input type="checkbox" name="grande_vulto_engenharia" value="1" @checked(old('grande_vulto_engenharia'))> Obra de grande vulto (garantia até 30%)</label>
            </div>
        </div>
        <div class="form-acoes"><a href="{{ route('contratos.index') }}" class="btn btn-s">Cancelar</a><button class="btn btn-p" type="submit">Cadastrar contrato</button></div>
    </form>
</x-layouts.app>
