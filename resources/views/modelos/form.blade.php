<x-layouts.app :titulo="$modelo->exists ? $modelo->titulo : 'Novo modelo'">
    @php($def = $tipos[$modelo->tipo])
    <div>
        <a href="{{ route('modelos.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Modelos</a>
        <h1 class="h1" style="margin-top:8px">{{ $modelo->exists ? $modelo->titulo : 'Novo modelo' }}</h1>
        <p class="sub">{{ $def['rotulo'] }} <span class="lei">{{ $def['base'] }}</span>@if ($modelo->exists) · versão {{ $modelo->versao }}@endif</p>
    </div>

    <details class="card"><summary style="font-weight:600;cursor:pointer">Campos dinâmicos que você pode usar no texto</summary>
        <div class="list" style="margin-top:10px">@foreach (\App\Models\ModeloDocumento::CAMPOS_DINAMICOS as $k => $d)<div class="li"><code>&#123;&#123;{{ $k }}&#125;&#125;</code><div>{{ $d }}</div></div>@endforeach</div>
    </details>

    <form method="POST" action="{{ $modelo->exists ? route('modelos.update', $modelo) : route('modelos.store') }}" class="card" novalidate>
        @csrf @if ($modelo->exists) @method('PUT') @endif
        @unless ($modelo->exists)<input type="hidden" name="tipo" value="{{ $modelo->tipo }}">@endunless
        <div class="form-grid">
            <x-campo nome="titulo" rotulo="Título do modelo" :valor="$modelo->titulo" obrigatorio />
            <x-campo nome="aplicacao" rotulo="Vale para" obrigatorio><select id="campo-aplicacao" name="aplicacao" class="input">@foreach (\App\Models\Fluxo::APLICACOES as $v => $r)<option value="{{ $v }}" @selected(old('aplicacao', $modelo->aplicacao) === $v)>{{ $r }}</option>@endforeach</select></x-campo>
            <x-campo nome="categoria" rotulo="Categoria"><select id="campo-categoria" name="categoria" class="input"><option value="">Qualquer categoria</option>@foreach (\App\Licitacao\Enums\CategoriaProcesso::cases() as $c)<option value="{{ $c->value }}" @selected((int) old('categoria', $modelo->categoria) === $c->value)>{{ $c->rotulo() }}</option>@endforeach</select></x-campo>
            <div class="campo full"><label for="descricao">Observações de uso</label><textarea id="descricao" name="descricao" class="input" rows="3">{{ old('descricao', $modelo->descricao) }}</textarea></div>
            @foreach ($def['campos'] as $campo => $d)
                <div class="campo full"><label for="c-{{ $campo }}">{{ $d['rotulo'] }}</label><textarea id="c-{{ $campo }}" name="conteudo[{{ $campo }}]" class="input" rows="6">{{ old("conteudo.$campo", $modelo->conteudo[$campo] ?? '') }}</textarea></div>
            @endforeach
            <div class="campo full"><label class="check"><input type="hidden" name="ativo" value="0"><input type="checkbox" name="ativo" value="1" @checked(old('ativo', $modelo->ativo ?? true))> Ativo (aparece para escolha nos processos)</label></div>
        </div>
        <div class="form-acoes">
            @if ($modelo->exists)<button class="btn btn-s" type="submit" form="duplicar">Duplicar</button>@endif
            <a href="{{ route('modelos.index') }}" class="btn btn-s">Voltar</a><button class="btn btn-p" type="submit">Salvar nova versão</button>
        </div>
    </form>
    @if ($modelo->exists)<form id="duplicar" method="POST" action="{{ route('modelos.duplicar', $modelo) }}">@csrf</form>

        <section class="card"><h2>Histórico de versões</h2>
            <div class="list">
                @forelse ($modelo->versoes as $v)
                    <div class="li"><b>v{{ $v->versao }}</b><div>{{ $v->titulo }}<div class="meta">{{ $v->created_at?->format('d/m/Y H:i') }} · {{ $v->autor?->name ?? 'sistema' }}</div></div>
                        <form method="POST" action="{{ route('modelos.restaurar', [$modelo, $v->versao]) }}" style="margin-left:auto">@csrf<button class="btn btn-s btn-sm" type="submit" onclick="return confirm('Restaurar esta versão como a atual?')">Restaurar</button></form></div>
                @empty
                    <div class="li muted">Ainda não há versões anteriores.</div>
                @endforelse
            </div>
        </section>
    @endif
</x-layouts.app>
