@php($M = \App\Licitacao\Regras\MatrizRiscos::class)
@php($podeRiscos = auth()->user()->can('processos.gerenciar') && $processo->editavel())
<section class="card">
    <div class="row" style="align-items:flex-start">
        <div>
            <h2>Matriz de riscos</h2>
            <p class="hint">Probabilidade, impacto, nível, a quem cabe e o tratamento de cada risco <span class="lei">art. 6º, XXVII; art. 18, X; art. 22; art. 103</span></p>
        </div>
        <div class="btns">
            @if ($podeRiscos && \App\Licitacao\Ia\AssistenteIa::habilitada())
                @can('ia.usar')<form method="POST" action="{{ route('processos.riscos.ia', $processo) }}">@csrf<button class="btn btn-s btn-sm" type="submit"><x-icone nome="editar" />Propor riscos com a IA</button></form>@endcan
            @endif
            @if ($podeRiscos && $riscos->isNotEmpty())
                <form method="POST" action="{{ route('processos.riscos.peca', $processo) }}">@csrf<button class="btn btn-p btn-sm" type="submit">Preencher a peça "Mapa de riscos"</button></form>
            @endif
        </div>
    </div>
    @if ($matrizObrigatoria && $riscos->isEmpty())
        <div class="aviso-lei" style="margin-top:10px">Obra ou serviço de engenharia de grande vulto: a matriz de alocação de riscos é obrigatória <span class="lei">art. 22, § 3º</span></div>
    @endif

    @if ($riscosIa)
        <form method="POST" action="{{ route('processos.riscos.aceitar', $processo) }}" class="card" style="margin-top:14px;background:#F4FAFF">
            @csrf
            <h3 style="margin:0 0 6px;font-size:15px">Proposta da NexMind IA</h3>
            <p class="hint">Texto gerado por IA. Marque só o que se aplica: a responsabilidade pela matriz é de quem a elabora.</p>
            <div class="list" style="margin-top:8px">
                @foreach ($riscosIa as $k => $r)
                    @php($n = $M::nivel($r['probabilidade'], $r['impacto']))
                    <label class="li" for="ia-{{ $k }}" style="cursor:pointer"><input id="ia-{{ $k }}" type="checkbox" name="escolhidas[]" value="{{ $k }}" checked>
                        <div><b>{{ $r['risco'] }}</b> <span class="b {{ $M::classe($n)[1] }}">{{ $M::classe($n)[0] }} · {{ $n }}</span>
                            <div class="meta">Probabilidade {{ $r['probabilidade'] }} · impacto {{ $r['impacto'] }} · {{ $M::ALOCACOES[$r['alocacao']] }}@if ($r['responsavel']) · {{ $r['responsavel'] }}@endif</div>
                            @if ($r['tratamento'])<div class="small">{{ $r['tratamento'] }}</div>@endif
                        </div></label>
                @endforeach
            </div>
            <div class="btns" style="margin-top:10px"><button class="btn btn-p btn-sm" type="submit">Aceitar os marcados</button></div>
        </form>
    @endif

    <div class="tbl-wrap" style="margin-top:14px"><table class="tbl"><thead><tr><th>#</th><th>Risco</th><th>P × I</th><th>Nível</th><th>Alocação</th><th>Tratamento</th>@if ($podeRiscos)<th class="acoes"><span class="sr">Ações</span></th>@endif</tr></thead><tbody>
        @forelse ($riscos as $r)
            @php($n = $r->nivel())
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td><b>{{ $r->risco }}</b>@if ($r->causa)<div class="small muted">Causa: {{ $r->causa }}</div>@endif @if ($r->consequencia)<div class="small muted">Consequência: {{ $r->consequencia }}</div>@endif @if ($r->origem === 'ia')<span class="b b-mute">proposto pela IA</span>@endif</td>
                <td>{{ $r->probabilidade }} × {{ $r->impacto }}</td>
                <td><span class="b {{ $M::classe($n)[1] }}">{{ $M::classe($n)[0] }} · {{ $n }}</span></td>
                <td>{{ $M::ALOCACOES[$r->alocacao] }}</td>
                <td class="small">{{ $r->tratamento ?? '—' }}@if ($r->responsavel)<div class="muted">{{ $r->responsavel }}</div>@endif</td>
                @if ($podeRiscos)<td class="acoes"><form method="POST" action="{{ route('processos.riscos.destroy', [$processo, $r]) }}" onsubmit="return confirm('Retirar o risco da matriz?')">@csrf @method('DELETE')<button class="btn btn-g btn-sm" type="submit">Retirar</button></form></td>@endif
            </tr>
        @empty
            <tr><td colspan="7" class="muted">Nenhum risco registrado.</td></tr>
        @endforelse
    </tbody></table></div>

    @if ($podeRiscos)
        <details style="margin-top:14px" @if ($errors->has('risco')) open @endif><summary class="btn btn-s btn-sm" style="list-style:none">+ Incluir risco</summary>
            <form method="POST" action="{{ route('processos.riscos.store', $processo) }}" class="form-grid" style="margin-top:12px">
                @csrf
                <x-campo nome="risco" rotulo="Risco" obrigatorio classe="full" maxlength="500" ajuda="Ex.: atraso na entrega por falta do produto no mercado" />
                <x-campo nome="causa" rotulo="Causa" maxlength="1000" />
                <x-campo nome="consequencia" rotulo="Consequência" maxlength="1000" />
                <x-campo nome="probabilidade" rotulo="Probabilidade" obrigatorio><select id="campo-probabilidade" name="probabilidade" class="input">@foreach ($M::ESCALA as $v => $t)<option value="{{ $v }}" @selected((int) old('probabilidade', 3) === $v)>{{ $v }} · {{ $t }}</option>@endforeach</select></x-campo>
                <x-campo nome="impacto" rotulo="Impacto" obrigatorio><select id="campo-impacto" name="impacto" class="input">@foreach ($M::ESCALA as $v => $t)<option value="{{ $v }}" @selected((int) old('impacto', 3) === $v)>{{ $v }} · {{ $t }}</option>@endforeach</select></x-campo>
                <x-campo nome="alocacao" rotulo="A quem cabe" obrigatorio><select id="campo-alocacao" name="alocacao" class="input">@foreach ($M::ALOCACOES as $v => $t)<option value="{{ $v }}" @selected(old('alocacao') === $v)>{{ $t }}</option>@endforeach</select></x-campo>
                <x-campo nome="responsavel" rotulo="Responsável (função)" maxlength="200" />
                <x-campo nome="tratamento" rotulo="Prevenção ou contingência" classe="full"><textarea id="campo-tratamento" name="tratamento" class="input" rows="2" maxlength="5000">{{ old('tratamento') }}</textarea></x-campo>
                <div class="btns full"><button class="btn btn-p btn-sm" type="submit">Incluir</button></div>
            </form>
        </details>
    @endif
</section>
