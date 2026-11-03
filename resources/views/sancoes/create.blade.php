<x-layouts.app titulo="Instaurar processo sancionador">
    <div><a href="{{ route('sancoes.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Processos sancionadores</a>
        <h1 class="h1" style="margin-top:8px">Instaurar processo sancionador</h1>
        <p class="sub">A instauração aponta os fatos, as infrações e a sanção em tese. A sanção final só sai depois da defesa. <span class="lei">arts. 155, 156 e 158</span></p></div>

    <section class="card">
        <form method="POST" action="{{ route('sancoes.store') }}" class="form-grid">@csrf
            <x-campo nome="fornecedor_id" rotulo="Fornecedor" obrigatorio>
                <select id="campo-fornecedor_id" name="fornecedor_id" class="input" required><option value="">Escolha</option>@foreach ($fornecedores as $f)<option value="{{ $f->id }}" @selected(old('fornecedor_id', $fornecedorId) == $f->id)>{{ $f->razao_social }} · {{ $f->documento }}</option>@endforeach</select>
            </x-campo>
            <x-campo nome="contrato_id" rotulo="Contrato (se a infração foi na execução)">
                <select id="campo-contrato_id" name="contrato_id" class="input"><option value="">Nenhum (infração no certame)</option>@foreach ($contratos as $c)<option value="{{ $c->id }}" @selected(old('contrato_id') == $c->id)>{{ $c->identificacao() }} · {{ \Illuminate\Support\Str::limit($c->objeto, 50) }}</option>@endforeach</select>
            </x-campo>

            <fieldset class="campo full" style="border:1px solid #CBEAEC;border-radius:10px;padding:10px 12px"><legend style="font-weight:600;padding:0 6px">Infrações apontadas (art. 155)</legend>
                @foreach (\App\Licitacao\Regras\Sancoes::INFRACOES as $inc => $txt)
                    <label style="display:flex;gap:8px;align-items:flex-start;font-weight:400;margin:4px 0"><input type="checkbox" name="infracoes[]" value="{{ $inc }}" @checked(in_array($inc, old('infracoes', [])))> <span><b>{{ $inc }}</b> · {{ $txt }}</span></label>
                @endforeach
            </fieldset>

            <x-campo nome="fatos" rotulo="Fatos: o que aconteceu, quando e qual cláusula ou regra do edital foi descumprida" obrigatorio classe="full">
                <textarea id="campo-fatos" name="fatos" class="input" rows="5" required>{{ old('fatos') }}</textarea>
            </x-campo>
            <x-campo nome="sancao_proposta" rotulo="Sanção em tese" obrigatorio>
                <select id="campo-sancao_proposta" name="sancao_proposta" class="input">@foreach (\App\Models\SancaoFornecedor::TIPOS as $v => $r)<option value="{{ $v }}" @selected(old('sancao_proposta') === $v)>{{ $r }}</option>@endforeach</select>
            </x-campo>
            <x-campo nome="justificativa_agravamento" rotulo="Justificativa de sanção mais grave que a prevista para a infração (art. 156, §§ 2º, 4º e 5º)" classe="full">
                <textarea id="campo-justificativa_agravamento" name="justificativa_agravamento" class="input" rows="2">{{ old('justificativa_agravamento') }}</textarea>
            </x-campo>

            <fieldset class="campo full" style="border:1px solid #CBEAEC;border-radius:10px;padding:10px 12px"><legend style="font-weight:600;padding:0 6px">Comissão processante (obrigatória para impedimento e inidoneidade, art. 158)</legend>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:4px">
                    @foreach ($servidores as $s)<label style="display:flex;gap:8px;font-weight:400"><input type="checkbox" name="comissao[]" value="{{ $s->id }}" @checked(in_array($s->id, old('comissao', [])))> {{ $s->name }}</label>@endforeach
                </div>
                <label style="display:flex;gap:8px;font-weight:400;margin-top:8px"><input type="checkbox" name="comissao_estaveis" value="1" @checked(old('comissao_estaveis'))> Os membros são servidores estáveis ou empregados públicos com 3 anos ou mais de tempo de serviço.</label>
            </fieldset>

            <div class="form-acoes full"><button class="btn btn-p" type="submit">Instaurar</button></div>
        </form>
    </section>
</x-layouts.app>
