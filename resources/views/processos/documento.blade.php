<x-layouts.app :titulo="$def['rotulo']">
    <div>
        <a href="{{ route('processos.show', [$processo, 'aba' => 'instrucao']) }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Processo {{ $processo->identificacao() }}</a>
        <h1 class="h1" style="margin-top:8px">{{ $def['rotulo'] }}</h1>
        <p class="sub"><span class="lei">{{ $def['base'] }}</span> @if ($atual) · versão {{ $atual->versao }}, {{ $atual->situacao === 'concluido' ? 'concluída em '.$atual->concluido_em->format('d/m/Y H:i') : 'rascunho' }}@endif</p>
    </div>

    @if ($tipo === 'etp')
        <div class="aviso-lei">Os incisos I, IV, VI, VIII e XIII são obrigatórios. Os outros podem ficar em branco, desde que a ausência seja justificada no campo final (art. 18, § 2º).</div>
    @endif

    <form method="POST" action="{{ route('documentos.salvar', [$processo, $tipo]) }}" class="card">
        @csrf
        <div class="form-grid">
            @foreach ($def['campos'] as $campo => $d)
                <div class="campo full">
                    <label for="c-{{ $campo }}">{{ $d['rotulo'] }}@if ($d['obrigatorio']) <span aria-hidden="true" style="color:#9F1D1D">*</span>@endif</label>
                    <textarea id="c-{{ $campo }}" name="conteudo[{{ $campo }}]" class="input" rows="4" @disabled(! $podeEditar)>{{ old("conteudo.$campo", $atual?->conteudo[$campo] ?? '') }}</textarea>
                    @isset($d['ajuda'])<span class="ajuda">{{ $d['ajuda'] }}</span>@endisset
                </div>
            @endforeach
        </div>
        @if ($podeEditar)
            <div class="form-acoes">
                @if ($atual?->situacao === 'concluido')<span class="small muted" style="margin-right:auto">Salvar agora cria a versão {{ $atual->versao + 1 }}.</span>@endif
                <button class="btn btn-s" type="submit" name="acao" value="rascunho">Salvar rascunho</button>
                <button class="btn btn-p" type="submit" name="acao" value="concluir" onclick="return confirm('Concluir? A versão concluída não poderá mais ser alterada.')"><x-icone nome="ok" />Concluir</button>
            </div>
        @else
            <p class="small muted" style="margin-top:12px">Somente leitura: esta peça é feita por outro perfil ou o processo já passou desta etapa.</p>
        @endif
    </form>

    @if ($versoes->count() > 1)
        <section class="card">
            <h2>Versões</h2>
            <div class="list">
                @foreach ($versoes as $v)
                    <div class="li"><b>v{{ $v->versao }}</b><div>{{ $v->situacao === 'concluido' ? 'Concluída' : 'Rascunho' }} · {{ $v->autor?->name }}<div class="meta">{{ ($v->concluido_em ?? $v->updated_at)->format('d/m/Y H:i') }}@if ($v->hash_conteudo) · hash {{ substr($v->hash_conteudo, 0, 16) }}... @endif</div></div></div>
                @endforeach
            </div>
        </section>
    @endif
</x-layouts.app>
