@php
    use App\Licitacao\Enums\{Modalidade, AmparoLegal, CriterioJulgamento, ModoDisputa, CategoriaProcesso};
    $sel = fn ($campo, $v) => (int) old($campo, $processo->{$campo}) === $v;
@endphp
<x-layouts.app :titulo="$processo->exists ? 'Editar processo' : 'Novo processo'">
    <div>
        <a href="{{ $processo->exists ? route('processos.show', $processo) : route('processos.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />{{ $processo->exists ? 'Processo '.$processo->identificacao() : 'Processos' }}</a>
        <h1 class="h1" style="margin-top:8px">{{ $processo->exists ? 'Editar processo' : 'Novo processo' }}</h1>
    </div>
    <div class="aviso-lei">A forma eletrônica é a regra. Modalidade presencial exige justificativa e gravação da sessão (art. 17, § 2º). Na dispensa por valor, o sistema soma as compras da mesma natureza no ano para evitar fracionamento (art. 75, § 1º).</div>
    <form method="POST" action="{{ $processo->exists ? route('processos.update', $processo) : route('processos.store') }}" class="card" novalidate>
        @csrf @if ($processo->exists) @method('PUT') @endif
        <input type="hidden" name="demanda_id" value="{{ old('demanda_id', $processo->demanda_id) }}">
        <div class="form-grid">
            <x-campo nome="numero" rotulo="Número do processo" :valor="$processo->numero" obrigatorio maxlength="30" />
            <x-campo nome="ano" rotulo="Ano" tipo="number" :valor="$processo->ano" obrigatorio />
            <x-campo nome="numero_compra" rotulo="Número do edital ou aviso" :valor="$processo->numero_compra" maxlength="30" />
            <x-campo nome="unidade_id" rotulo="Unidade compradora" obrigatorio>
                <select id="campo-unidade_id" name="unidade_id" class="input">
                    <option value="">Escolha</option>
                    @foreach ($unidades as $u)<option value="{{ $u->id }}" @selected($sel('unidade_id', $u->id))>{{ $u->sigla }} · {{ $u->nome }}</option>@endforeach
                </select>
            </x-campo>
            <x-campo nome="objeto" rotulo="Objeto" obrigatorio classe="full">
                <textarea id="campo-objeto" name="objeto" class="input" rows="3" maxlength="5120">{{ old('objeto', $processo->objeto) }}</textarea>
            </x-campo>
            <x-campo nome="modalidade" rotulo="Modalidade ou forma" obrigatorio>
                <select id="campo-modalidade" name="modalidade" class="input">@foreach (Modalidade::opcoes() as $v => $r)<option value="{{ $v }}" @selected($sel('modalidade', $v))>{{ $r }}</option>@endforeach</select>
            </x-campo>
            <x-campo nome="amparo_legal" rotulo="Amparo legal" obrigatorio>
                <select id="campo-amparo_legal" name="amparo_legal" class="input">@foreach (AmparoLegal::opcoes() as $v => $r)<option value="{{ $v }}" @selected($sel('amparo_legal', $v))>{{ $r }}</option>@endforeach</select>
            </x-campo>
            <x-campo nome="categoria" rotulo="Categoria" obrigatorio>
                <select id="campo-categoria" name="categoria" class="input">@foreach (CategoriaProcesso::opcoes() as $v => $r)<option value="{{ $v }}" @selected($sel('categoria', $v))>{{ $r }}</option>@endforeach</select>
            </x-campo>
            <x-campo nome="criterio_julgamento" rotulo="Critério de julgamento" obrigatorio>
                <select id="campo-criterio_julgamento" name="criterio_julgamento" class="input">@foreach (CriterioJulgamento::opcoes() as $v => $r)<option value="{{ $v }}" @selected($sel('criterio_julgamento', $v))>{{ $r }}</option>@endforeach</select>
            </x-campo>
            <x-campo nome="modo_disputa" rotulo="Modo de disputa" obrigatorio>
                <select id="campo-modo_disputa" name="modo_disputa" class="input">@foreach (ModoDisputa::opcoes() as $v => $r)<option value="{{ $v }}" @selected($sel('modo_disputa', $v))>{{ $r }}</option>@endforeach</select>
            </x-campo>
            <x-campo nome="natureza_despesa" rotulo="Natureza do objeto" :valor="$processo->natureza_despesa" ajuda="Obrigatória na dispensa por valor. Ex.: 3.3.90.30.16 (material de expediente)." />
            <x-campo nome="responsavel_id" rotulo="Responsável">
                <select id="campo-responsavel_id" name="responsavel_id" class="input">
                    <option value="">Não definido</option>
                    @foreach ($responsaveis as $r)<option value="{{ $r->id }}" @selected($sel('responsavel_id', $r->id))>{{ $r->name }}</option>@endforeach
                </select>
            </x-campo>
            <x-campo nome="abertura_propostas_em" rotulo="Abertura das propostas" tipo="datetime-local" :valor="$processo->abertura_propostas_em?->format('Y-m-d\TH:i')" />
            <x-campo nome="encerramento_propostas_em" rotulo="Encerramento das propostas" tipo="datetime-local" :valor="$processo->encerramento_propostas_em?->format('Y-m-d\TH:i')" />
            <div class="campo full" style="flex-direction:row;flex-wrap:wrap;gap:8px 24px">
                <label class="check"><input type="checkbox" name="srp" value="1" @checked(old('srp', $processo->srp))> Registro de preços (art. 82)</label>
                <label class="check"><input type="checkbox" name="orcamento_sigiloso" value="1" @checked(old('orcamento_sigiloso', $processo->orcamento_sigiloso))> Orçamento sigiloso (art. 24)</label>
                <label class="check"><input type="checkbox" name="com_disputa" value="1" @checked(old('com_disputa', $processo->com_disputa))> Dispensa com disputa eletrônica (art. 75, § 3º)</label>
            </div>
        </div>
        <div class="form-acoes"><a href="{{ $processo->exists ? route('processos.show', $processo) : route('processos.index') }}" class="btn btn-s">Cancelar</a><button class="btn btn-p" type="submit">{{ $processo->exists ? 'Salvar' : 'Abrir processo' }}</button></div>
    </form>
</x-layouts.app>
