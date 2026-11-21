@php($selecionadas = array_map('intval', (array) old('participantes', $plano?->participantes->pluck('id')->all() ?? [])))
@php($abrangencia = old('abrangencia', $plano->abrangencia ?? 'orgao'))
@php($comObjetivo = (bool) old('possui_objetivo_estrategico', $plano->possui_objetivo_estrategico ?? false))
<x-layouts.app :titulo="$plano ? 'Cadastro do PCA '.$plano->ano : 'Novo PCA'">
    <div>
        <a href="{{ $plano ? route('pca.show', $plano) : route('pca.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />{{ $plano ? 'PCA '.$plano->ano : 'Planos' }}</a>
        <h1 class="h1" style="margin-top:8px">{{ $plano ? 'Cadastro do PCA '.$plano->ano : 'Novo plano de contratações anual' }}</h1>
        <p class="sub">Defina quem participa, como as demandas se consolidam e o calendário de elaboração do plano. <span class="lei">art. 12, VII, e § 1º</span></p>
    </div>

    <form method="POST" action="{{ $plano ? route('pca.update', $plano) : route('pca.store') }}" class="card" novalidate>
        @csrf @if ($plano) @method('PUT') @endif
        <div class="form-grid">
            @if (! $plano)
                <x-campo nome="ano" rotulo="Exercício" tipo="number" :valor="$proximoAno" obrigatorio min="2023" max="2100" />
            @endif
            <x-campo nome="abrangencia" rotulo="Abrangência" obrigatorio ajuda="O plano do órgão todo recebe DFD de qualquer unidade. Por unidades, só das participantes e dos setores delas.">
                <select id="campo-abrangencia" name="abrangencia" class="input">
                    @foreach (\App\Models\PlanoContratacao::ABRANGENCIAS as $v => $r)<option value="{{ $v }}" @selected($abrangencia === $v)>{{ $r }}</option>@endforeach
                </select>
            </x-campo>
            <x-campo nome="tipo_consolidacao" rotulo="Tipo de consolidação" obrigatorio ajuda="Por unidade: os DFDs dos setores somam na unidade participante. Por setor: cada setor tem as suas linhas no plano.">
                <select id="campo-tipo_consolidacao" name="tipo_consolidacao" class="input">
                    @foreach (\App\Models\PlanoContratacao::TIPOS_CONSOLIDACAO as $v => $r)<option value="{{ $v }}" @selected(old('tipo_consolidacao', $plano->tipo_consolidacao ?? 'unidade') === $v)>{{ $r }}</option>@endforeach
                </select>
            </x-campo>
            <x-campo nome="unidade_responsavel_id" rotulo="Unidade responsável" obrigatorio ajuda="Conduz a elaboração e a consolidação do plano.">
                <select id="campo-unidade_responsavel_id" name="unidade_responsavel_id" class="input">
                    <option value="">Escolha</option>
                    @foreach ($unidades as $u)<option value="{{ $u->id }}" @selected((int) old('unidade_responsavel_id', $plano?->unidade_responsavel_id) === $u->id)>{{ $u->sigla }} · {{ $u->nome }}</option>@endforeach
                </select>
            </x-campo>
            <x-campo nome="nivel_detalhamento" rotulo="Nível de detalhamento" obrigatorio ajuda="Com item do catálogo, cada item de DFD precisa do código CATMAT/CATSER.">
                <select id="campo-nivel_detalhamento" name="nivel_detalhamento" class="input">
                    @foreach (\App\Models\PlanoContratacao::NIVEIS_DETALHAMENTO as $v => $r)<option value="{{ $v }}" @selected(old('nivel_detalhamento', $plano->nivel_detalhamento ?? 'item') === $v)>{{ $r }}</option>@endforeach
                </select>
            </x-campo>
            <x-campo nome="possui_objetivo_estrategico" rotulo="Possui objetivo estratégico?" obrigatorio ajuda="O PCA se alinha ao planejamento estratégico do órgão (art. 12, VII).">
                <select id="campo-possui_objetivo_estrategico" name="possui_objetivo_estrategico" class="input">
                    <option value="0" @selected(! $comObjetivo)>Não</option>
                    <option value="1" @selected($comObjetivo)>Sim</option>
                </select>
            </x-campo>
            <x-campo nome="objetivo_estrategico" rotulo="Objetivo estratégico" classe="full">
                <textarea id="campo-objetivo_estrategico" name="objetivo_estrategico" class="input" rows="3">{{ old('objetivo_estrategico', $plano?->objetivo_estrategico) }}</textarea>
            </x-campo>
        </div>

        <h2 style="margin-top:22px">Calendário de elaboração</h2>
        <p class="hint">O fim do cadastro dos DFDs vira a data-limite das demandas do exercício. Depois dela, só entram as exceções justificadas.</p>
        <div class="form-grid" style="margin-top:10px">
            @foreach (\App\Licitacao\Regras\CalendarioPca::ETAPAS as $c => $r)
                <x-campo :nome="$c" :rotulo="$r" tipo="date" :valor="$plano?->getAttribute($c)?->format('Y-m-d')" obrigatorio />
            @endforeach
        </div>

        <fieldset id="bloco-participantes" style="border:0;padding:0;margin:22px 0 0" @if ($abrangencia !== 'unidades') hidden @endif>
            <legend><h2 style="margin:0">Unidades participantes</h2></legend>
            @error('participantes')<span class="erro" role="alert">{{ $message }}</span>@enderror
            <div class="grid3" style="margin-top:10px">
                @foreach ($unidades as $u)
                    <label class="small" style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="participantes[]" value="{{ $u->id }}" @checked(in_array($u->id, $selecionadas, true))>{{ $u->sigla }} · {{ $u->nome }}</label>
                @endforeach
            </div>
            <div class="btns" style="margin-top:8px"><button type="button" class="btn btn-g btn-sm" data-marcar="1">Todas</button><button type="button" class="btn btn-g btn-sm" data-marcar="0">Nenhuma</button></div>
        </fieldset>

        <div class="form-acoes"><a href="{{ $plano ? route('pca.show', $plano) : route('pca.index') }}" class="btn btn-s">Voltar</a><button class="btn btn-p" type="submit"><x-icone nome="ok" />Salvar</button></div>
    </form>
    <script>
        (() => {
            const bloco = document.getElementById('bloco-participantes');
            document.getElementById('campo-abrangencia').addEventListener('change', e => { bloco.hidden = e.target.value !== 'unidades'; });
            bloco.querySelectorAll('[data-marcar]').forEach(b => b.addEventListener('click', () => bloco.querySelectorAll('input[type=checkbox]').forEach(c => { c.checked = b.dataset.marcar === '1'; })));
        })();
    </script>
</x-layouts.app>
