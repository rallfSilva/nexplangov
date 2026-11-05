<x-layouts.app :titulo="$fluxo->exists ? $fluxo->nome : 'Novo fluxo'">
    <div>
        <a href="{{ route('fluxos.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Fluxos</a>
        <h1 class="h1" style="margin-top:8px">{{ $fluxo->exists ? $fluxo->nome : 'Novo fluxo' }}</h1>
    </div>

    @if ($errors->any())
        <div class="flash flash-erro" role="alert"><b>Corrija antes de salvar:</b><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ $fluxo->exists ? route('fluxos.update', $fluxo) : route('fluxos.store') }}" class="card" novalidate>
        @csrf @if ($fluxo->exists) @method('PUT') @endif
        <div class="form-grid">
            <x-campo nome="nome" rotulo="Nome do fluxo" :valor="$fluxo->nome" obrigatorio />
            <x-campo nome="aplicacao" rotulo="Vale para" obrigatorio><select id="campo-aplicacao" name="aplicacao" class="input">@foreach (\App\Models\Fluxo::APLICACOES as $v => $r)<option value="{{ $v }}" @selected(old('aplicacao', $fluxo->aplicacao) === $v)>{{ $r }}</option>@endforeach</select></x-campo>
            <x-campo nome="modalidade" rotulo="Tipo de processo (modalidade)" ajuda="Em branco: vale para todas."><select id="campo-modalidade" name="modalidade" class="input"><option value="">Todas</option>@foreach (\App\Licitacao\Enums\Modalidade::opcoes() as $v => $r)<option value="{{ $v }}" @selected((string) old('modalidade', $fluxo->modalidade) === (string) $v)>{{ $r }}</option>@endforeach</select></x-campo>
            <x-campo nome="unidade_id" rotulo="Secretaria que abre o processo" ajuda="Em branco: vale para todas. O fluxo da secretaria vence o da modalidade."><select id="campo-unidade_id" name="unidade_id" class="input"><option value="">Todas</option>@foreach ($unidades as $un)<option value="{{ $un->id }}" @selected((int) old('unidade_id', $fluxo->unidade_id) === $un->id)>{{ $un->sigla }} · {{ $un->nome }}</option>@endforeach</select></x-campo>
            <x-campo nome="descricao" rotulo="Descrição" :valor="$fluxo->descricao" />
            <div class="campo"><label class="check"><input type="hidden" name="padrao" value="0"><input type="checkbox" name="padrao" value="1" @checked(old('padrao', $fluxo->padrao))> Usar como fluxo padrão</label>
                <label class="check"><input type="hidden" name="ativo" value="0"><input type="checkbox" name="ativo" value="1" @checked(old('ativo', $fluxo->ativo ?? true))> Ativo</label></div>
        </div>

        <h2 style="margin-top:22px">Roteiro de unidades</h2>
        <p class="hint">Ordem das unidades por onde o processo costuma passar. Na remessa, o sistema sugere a próxima unidade do roteiro; quem envia pode escolher outra.</p>
        @php($roteiro = old('roteiro_unidades', $fluxo->roteiro_unidades ?? []))
        <div class="form-grid" style="margin-top:8px">
            @for ($r = 0; $r < 6; $r++)
                <div class="campo"><label for="roteiro-{{ $r }}">{{ $r + 1 }}ª unidade</label>
                    <select id="roteiro-{{ $r }}" name="roteiro_unidades[]" class="input"><option value="">—</option>@foreach ($unidades as $un)<option value="{{ $un->id }}" @selected((int) ($roteiro[$r] ?? 0) === $un->id)>{{ $un->sigla }}</option>@endforeach</select></div>
            @endfor
        </div>

        <h2 style="margin-top:22px">Etapas</h2>
        <p class="hint">Marque as peças que fazem parte do fluxo. A ordem é a da lista. "Depende de" bloqueia a peça até as outras estarem concluídas. Parecer jurídico e autorização são assinados por quem tem a competência. Quem faz a peça nunca revisa nem aprova o próprio texto. <span class="lei">Art. 18 e art. 53</span></p>

        @php($i = 0)
        @foreach ($tipos as $tipo => $def)
            @php($e = $porTipo[$tipo] ?? null)
            @php($inc = old("etapas.$i.incluir", $e ? 1 : 0))
            <fieldset style="border:1px solid #D7E5EE;border-radius:14px;padding:14px 16px;margin:10px 0">
                <input type="hidden" name="etapas[{{ $i }}][tipo]" value="{{ $tipo }}">
                <label class="check"><input type="hidden" name="etapas[{{ $i }}][incluir]" value="0"><input type="checkbox" name="etapas[{{ $i }}][incluir]" value="1" @checked($inc)> <b>{{ $def['rotulo'] }}</b> <span class="lei">{{ $def['base'] }}</span></label>
                <div class="form-grid" style="margin-top:8px">
                    <div class="campo"><label for="{{ $_rot = 'c'.\Illuminate\Support\Str::random(8) }}">Depende de</label>
                        <select id="{{ $_rot }}" name="etapas[{{ $i }}][depende_de][]" class="input" multiple size="4">@foreach ($tipos as $t2 => $d2)@if ($t2 !== $tipo)<option value="{{ $t2 }}" @selected(in_array($t2, old("etapas.$i.depende_de", $e?->depende_de ?? [])))>{{ $d2['rotulo'] }}</option>@endif @endforeach</select></div>
                    <div class="campo"><label for="{{ $_rot = 'c'.\Illuminate\Support\Str::random(8) }}">Prazo (dias após liberada)</label><input id="{{ $_rot }}" type="number" min="1" max="365" name="etapas[{{ $i }}][prazo_dias]" class="input" value="{{ old("etapas.$i.prazo_dias", $e?->prazo_dias) }}">
                        <label style="margin-top:8px" for="etapa-origem-{{ $i }}">Como é feita</label><select id="etapa-origem-{{ $i }}" name="etapas[{{ $i }}][origem]" class="input">@foreach (\App\Licitacao\Fluxo\FluxoDocumento::ORIGENS as $v => $r)<option value="{{ $v }}" @selected(old("etapas.$i.origem", $e?->origem ?? 'formulario') === $v)>{{ $r }}</option>@endforeach</select></div>
                    @foreach (['responsavel_id' => 'Responsável', 'substituto_id' => 'Substituto', 'revisor_id' => 'Revisor', 'aprovador_id' => 'Aprovador'] as $campo => $rot)
                        <div class="campo"><label for="{{ $_rot = 'c'.\Illuminate\Support\Str::random(8) }}">{{ $rot }}</label><select id="{{ $_rot }}" name="etapas[{{ $i }}][{{ $campo }}]" class="input"><option value="">Qualquer pessoa com permissão</option>@foreach ($usuarios as $u)<option value="{{ $u->id }}" @selected((int) old("etapas.$i.$campo", $e?->$campo) === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
                    @endforeach
                    <div class="campo"><label class="check"><input type="hidden" name="etapas[{{ $i }}][exige_revisao]" value="0"><input type="checkbox" name="etapas[{{ $i }}][exige_revisao]" value="1" @checked(old("etapas.$i.exige_revisao", $e?->exige_revisao))> Exige revisão</label>
                        <label class="check"><input type="hidden" name="etapas[{{ $i }}][exige_aprovacao]" value="0"><input type="checkbox" name="etapas[{{ $i }}][exige_aprovacao]" value="1" @checked(old("etapas.$i.exige_aprovacao", $e?->exige_aprovacao))> Exige aprovação</label>
                        <label class="check"><input type="hidden" name="etapas[{{ $i }}][exige_assinatura]" value="0"><input type="checkbox" name="etapas[{{ $i }}][exige_assinatura]" value="1" @checked(old("etapas.$i.exige_assinatura", $e?->exige_assinatura))> Exige assinatura eletrônica</label></div>
                    <div class="campo"><label for="{{ $_rot = 'c'.\Illuminate\Support\Str::random(8) }}">Signatários (se exige assinatura)</label>
                        <select id="{{ $_rot }}" name="etapas[{{ $i }}][assinantes][]" class="input" multiple size="4">@foreach ($usuarios as $u)<option value="{{ $u->id }}" @selected(in_array($u->id, old("etapas.$i.assinantes", $e?->assinantes ?? [])))>{{ $u->name }}</option>@endforeach</select>
                        <span class="ajuda">Em branco: assina quem fez a peça (parecer e autorização: quem decide).</span></div>
                </div>
            </fieldset>
            @php($i++)
        @endforeach

        <div class="form-acoes">
            @if ($fluxo->exists)<button class="btn btn-s" type="submit" form="excluir-fluxo" onclick="return confirm('Excluir este fluxo?')">Excluir</button>@endif
            <a href="{{ route('fluxos.index') }}" class="btn btn-s">Voltar</a><button class="btn btn-p" type="submit">Salvar</button>
        </div>
    </form>
    @if ($fluxo->exists)<form id="excluir-fluxo" method="POST" action="{{ route('fluxos.destroy', $fluxo) }}">@csrf @method('DELETE')</form>@endif
</x-layouts.app>
