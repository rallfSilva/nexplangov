<x-layouts.app :titulo="$orgao->exists ? 'Editar órgão' : 'Novo órgão'">
    <div>
        <a href="{{ route('orgaos.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Órgãos</a>
        <h1 class="h1" style="margin-top:8px">{{ $orgao->exists ? 'Editar '.$orgao->sigla : 'Novo órgão' }}</h1>
    </div>

    <form method="POST" action="{{ $orgao->exists ? route('orgaos.update', $orgao) : route('orgaos.store') }}" class="card" novalidate>
        @csrf
        @if ($orgao->exists) @method('PUT') @endif
        <div class="form-grid">
            <x-campo nome="nome" rotulo="Nome do órgão" :valor="$orgao->nome" obrigatorio classe="full" />
            <x-campo nome="sigla" rotulo="Sigla" :valor="$orgao->sigla" obrigatorio maxlength="30" />
            <x-campo nome="cnpj" rotulo="CNPJ" :valor="$orgao->cnpj" placeholder="00.000.000/0000-00" />
            <x-campo nome="esfera" rotulo="Esfera" obrigatorio>
                <select id="campo-esfera" name="esfera" class="input">
                    @foreach (['municipal' => 'Municipal', 'estadual' => 'Estadual', 'distrital' => 'Distrital', 'federal' => 'Federal'] as $valor => $rotulo)
                        <option value="{{ $valor }}" @selected(old('esfera', $orgao->esfera) === $valor)>{{ $rotulo }}</option>
                    @endforeach
                </select>
            </x-campo>
            <x-campo nome="poder" rotulo="Poder" obrigatorio>
                <select id="campo-poder" name="poder" class="input">
                    @foreach (['executivo' => 'Executivo', 'legislativo' => 'Legislativo', 'judiciario' => 'Judiciário', 'autonomo' => 'Órgão autônomo'] as $valor => $rotulo)
                        <option value="{{ $valor }}" @selected(old('poder', $orgao->poder) === $valor)>{{ $rotulo }}</option>
                    @endforeach
                </select>
            </x-campo>
            <x-campo nome="natureza_juridica" rotulo="Natureza jurídica (código RFB)" :valor="$orgao->natureza_juridica" placeholder="Ex.: 1244 (Município)" />
            <x-campo nome="uf" rotulo="UF" :valor="$orgao->uf" obrigatorio maxlength="2" />
            <x-campo nome="municipio" rotulo="Município" :valor="$orgao->municipio" obrigatorio />
            <x-campo nome="pncp_ambiente" rotulo="Ambiente do PNCP" obrigatorio>
                <select id="campo-pncp_ambiente" name="pncp_ambiente" class="input">
                    <option value="homologacao" @selected(old('pncp_ambiente', $orgao->pncp_ambiente) === 'homologacao')>Homologação (treinamento)</option>
                    <option value="producao" @selected(old('pncp_ambiente', $orgao->pncp_ambiente) === 'producao')>Produção</option>
                </select>
            </x-campo>
            <div class="campo full">
                <label class="check"><input type="checkbox" name="limite_dispensa_em_dobro" value="1" @checked(old('limite_dispensa_em_dobro', $orgao->limite_dispensa_em_dobro))> Limites de dispensa em dobro (consórcio público ou agência executiva, <span class="lei">art. 75, § 2º</span>)</label>
            </div>
            <div class="campo full">
                <label class="check"><input type="checkbox" name="pncp_orgao_preparado" value="1" @checked(old('pncp_orgao_preparado', $orgao->pncp_orgao_preparado))> Órgão e unidades já cadastrados no PNCP</label>
            </div>
            <div class="campo full">
                <label class="check"><input type="checkbox" name="ativo" value="1" @checked(old('ativo', $orgao->ativo))> Órgão ativo (desmarque para bloquear o acesso de todos os usuários dele)</label>
            </div>
        </div>
        <div class="form-acoes">
            <a href="{{ route('orgaos.index') }}" class="btn btn-s">Cancelar</a>
            <button type="submit" class="btn btn-p">Salvar</button>
        </div>
    </form>
</x-layouts.app>
