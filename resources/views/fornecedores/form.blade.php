<x-layouts.app :titulo="$fornecedor->exists ? $fornecedor->razao_social : 'Novo fornecedor'">
    <div>
        <a href="{{ route('fornecedores.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Fornecedores</a>
        <h1 class="h1" style="margin-top:8px">{{ $fornecedor->exists ? $fornecedor->razao_social : 'Novo fornecedor' }}</h1>
    </div>
    <form method="POST" action="{{ $fornecedor->exists ? route('fornecedores.update', $fornecedor) : route('fornecedores.store') }}" class="card" novalidate>
        @csrf @if ($fornecedor->exists) @method('PUT') @endif
        <div class="form-grid">
            @if ($fornecedor->exists)
                <div class="campo"><span class="rotulo">Documento</span><span class="valor">{{ $fornecedor->documento }}</span><span class="ajuda">Não muda depois do cadastro.</span></div>
            @else
                <x-campo nome="tipo_pessoa" rotulo="Tipo" obrigatorio><select id="campo-tipo_pessoa" name="tipo_pessoa" class="input"><option value="PJ" @selected(old('tipo_pessoa', 'PJ') === 'PJ')>Pessoa jurídica (CNPJ)</option><option value="PF" @selected(old('tipo_pessoa') === 'PF')>Pessoa física (CPF)</option></select></x-campo>
                <x-campo nome="documento" rotulo="CNPJ ou CPF" obrigatorio ajuda="CPF fica cifrado no banco e só aparece mascarado." />
            @endif
            <x-campo nome="razao_social" rotulo="Razão social ou nome" :valor="$fornecedor->razao_social" obrigatorio />
            <x-campo nome="nome_fantasia" rotulo="Nome fantasia" :valor="$fornecedor->nome_fantasia" />
            <x-campo nome="porte" rotulo="Porte"><select id="campo-porte" name="porte" class="input"><option value="">Não informado</option>@foreach (\App\Models\Fornecedor::PORTES as $v => $r)<option value="{{ $v }}" @selected(old('porte', $fornecedor->porte) === $v)>{{ $r }}</option>@endforeach</select></x-campo>
            <x-campo nome="email" rotulo="E-mail" tipo="email" :valor="$fornecedor->email" />
            <x-campo nome="telefone" rotulo="Telefone" :valor="$fornecedor->telefone" />
            <x-campo nome="municipio" rotulo="Município" :valor="$fornecedor->municipio" />
            <x-campo nome="uf" rotulo="UF" :valor="$fornecedor->uf" maxlength="2" />
            <div class="campo full"><label class="check"><input type="hidden" name="ativo" value="0"><input type="checkbox" name="ativo" value="1" @checked(old('ativo', $fornecedor->ativo ?? true))> Ativo</label></div>
        </div>
        <div class="form-acoes"><a href="{{ route('fornecedores.index') }}" class="btn btn-s">Voltar</a><button class="btn btn-p" type="submit">Salvar</button></div>
    </form>

    @if ($fornecedor->exists)
        <section class="card">
            <h2>Sanções</h2>
            <p class="hint">Impedimento e inidoneidade vigentes bloqueiam o fornecedor no resultado e no contrato. <span class="lei">Art. 156</span></p>
            <div class="list">
                @forelse ($fornecedor->sancoes as $s)
                    <div class="li"><span class="chip {{ in_array($s->tipo, ['impedimento', 'inidoneidade']) ? 'c-warn' : 'c-navy' }}"><x-icone nome="alerta" /></span><div>{{ \App\Models\SancaoFornecedor::TIPOS[$s->tipo] }}<div class="meta">{{ $s->inicio->format('d/m/Y') }} a {{ $s->fim?->format('d/m/Y') ?? 'indeterminado' }}@if ($s->processo) · {{ $s->processo }}@endif</div></div></div>
                @empty
                    <div class="li muted">Nenhuma sanção registrada.</div>
                @endforelse
            </div>
            <details style="margin-top:12px"><summary class="btn btn-s btn-sm" style="list-style:none">+ Registrar sanção</summary>
                <form method="POST" action="{{ route('fornecedores.sancoes.store', $fornecedor) }}" class="form-grid" style="margin-top:14px">
                    @csrf
                    <x-campo nome="tipo" rotulo="Tipo" obrigatorio><select id="campo-tipo" name="tipo" class="input">@foreach (\App\Models\SancaoFornecedor::TIPOS as $v => $r)<option value="{{ $v }}">{{ $r }}</option>@endforeach</select></x-campo>
                    <x-campo nome="inicio" rotulo="Início" tipo="date" obrigatorio />
                    <x-campo nome="fim" rotulo="Fim" tipo="date" />
                    <x-campo nome="processo" rotulo="Processo sancionador" />
                    <x-campo nome="fundamento" rotulo="Fundamento" obrigatorio classe="full"><textarea id="campo-fundamento" name="fundamento" class="input" rows="3">{{ old('fundamento') }}</textarea></x-campo>
                    <div class="campo"><button class="btn btn-p" type="submit">Registrar</button></div>
                </form>
            </details>
        </section>
    @endif
</x-layouts.app>
