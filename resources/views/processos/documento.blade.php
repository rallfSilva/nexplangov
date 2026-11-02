<x-layouts.app :titulo="$def['rotulo']">
    @php($situacoes = ['rascunho' => 'Rascunho', 'em_revisao' => 'Em revisão', 'em_aprovacao' => 'Em aprovação', 'aguardando_assinatura' => 'Aguardando assinatura', 'concluido' => 'Concluída', 'substituido' => 'Substituída'])
    <div>
        <a href="{{ route('processos.show', [$processo, 'aba' => 'instrucao']) }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Processo {{ $processo->identificacao() }}</a>
        <h1 class="h1" style="margin-top:8px">{{ $def['rotulo'] }}</h1>
        <p class="sub"><span class="lei">{{ $def['base'] }}</span> @if ($atual) · versão {{ $atual->versao }}, <span class="b {{ $estado['classe'] }}">{{ $estado['rotulo'] }}</span>@else · <span class="b {{ $estado['classe'] }}">{{ $estado['rotulo'] }}</span>@endif
            @if ($etapa?->prazo_em) · prazo {{ $etapa->prazo_em->format('d/m/Y') }}@if ($etapa->prazoVencido()) <span class="b b-err">vencido</span>@endif @endif</p>
    </div>

    @if ($estado['pendencias'])
        <div class="aviso-lei" style="border-color:#E0A458;background:#FFF7EC;color:#7A3B0B">Esta peça depende de: <b>{{ implode(', ', $estado['pendencias']) }}</b>. Conclua essas peças antes.</div>
    @endif

    @if ($atual?->motivo_devolucao && $atual->situacao === 'rascunho')
        <div class="aviso-lei" style="border-color:#E0A458;background:#FFF7EC;color:#7A3B0B"><b>Devolvida para ajustes:</b> {{ $atual->motivo_devolucao }}</div>
    @endif

    @if ($tipo === 'etp')
        <div class="aviso-lei">Os incisos I, IV, VI, VIII e XIII são obrigatórios. Os outros podem ficar em branco, desde que a ausência seja justificada no campo final (art. 18, § 2º).</div>
    @endif

    @if ($podeEditar && $modelos->isNotEmpty())
        <form method="GET" class="card filtros" style="align-items:flex-end">
            <div class="campo"><label for="m-sel">Começar a partir de um modelo da biblioteca</label>
                <select id="m-sel" name="modelo" class="input"><option value="">Em branco</option>@foreach ($modelos as $m)<option value="{{ $m->id }}" @selected($modelo?->id === $m->id)>{{ $m->titulo }}</option>@endforeach</select>
                <span class="ajuda">Os campos como @{{objeto}} são preenchidos com os dados do processo. O texto é só ponto de partida: revise com a assessoria jurídica.</span></div>
            <div class="btns"><button class="btn btn-s" type="submit">Aplicar modelo</button></div>
        </form>
        @if ($modelo)<div class="aviso-lei">Modelo "{{ $modelo->titulo }}" (v{{ $modelo->versao }}) aplicado na tela. Nada foi salvo ainda.</div>@endif
    @endif

    <form method="POST" action="{{ route('documentos.salvar', [$processo, $tipo]) }}" class="card" enctype="multipart/form-data">
        @csrf
        @if ($modelo)<input type="hidden" name="modelo_id" value="{{ $modelo->id }}">@endif
        @php($origemAtual = old('origem', $etapa?->origem === 'arquivo' ? 'arquivo' : ($atual?->origem ?? 'formulario')))
        @if ($podeEditar && $etapa?->origem !== 'arquivo')
            <div class="campo full" style="margin-bottom:14px"><label for="origem">Como a peça será feita</label>
                <select id="origem" name="origem" class="input" onchange="document.getElementById('bloco-arquivo').hidden=this.value!=='arquivo';document.getElementById('bloco-form').hidden=this.value==='arquivo'">
                    <option value="formulario" @selected($origemAtual === 'formulario')>Preencher aqui no sistema</option><option value="arquivo" @selected($origemAtual === 'arquivo')>Enviar um documento já pronto</option></select></div>
        @elseif ($etapa?->origem === 'arquivo') <input type="hidden" name="origem" value="arquivo"> @endif

        <div id="bloco-arquivo" @if ($origemAtual !== 'arquivo') hidden @endif>
            @if ($atual?->arquivo_caminho)
                <p class="small">Arquivo atual: <a href="{{ route('documentos.arquivo', [$processo, $atual]) }}" style="font-weight:600">{{ $atual->arquivo_nome }}</a> · {{ number_format($atual->arquivo_tamanho / 1024, 0, ',', '.') }} KB · SHA-256 <span class="valor">{{ substr($atual->arquivo_hash, 0, 16) }}...</span></p>
            @endif
            @if ($podeEditar)
                <div class="campo full"><label for="arquivo">Arquivo (PDF, DOCX, ODT, XLSX, ODS, PNG ou JPG, até 30 MB)</label><input id="arquivo" type="file" name="arquivo" class="input" accept=".pdf,.docx,.odt,.xlsx,.ods,.png,.jpg,.jpeg">
                    @error('arquivo')<span class="erro">{{ $message }}</span>@enderror</div>
            @endif
        </div>

        <div id="bloco-form" class="form-grid" @if ($origemAtual === 'arquivo') hidden @endif>
            @foreach ($def['campos'] as $campo => $d)
                <div class="campo full">
                    <label for="c-{{ $campo }}">{{ $d['rotulo'] }}@if ($d['obrigatorio']) <span aria-hidden="true" style="color:#9F1D1D">*</span>@endif</label>
                    <textarea id="c-{{ $campo }}" name="conteudo[{{ $campo }}]" class="input" rows="5" @disabled(! $podeEditar)>{{ old("conteudo.$campo", $valores[$campo] ?? $atual?->conteudo[$campo] ?? '') }}</textarea>
                    @isset($d['ajuda'])<span class="ajuda">{{ $d['ajuda'] }}</span>@endisset
                </div>
            @endforeach
        </div>

        @if ($podeEditar)
            <div class="form-acoes">
                <span class="small muted" style="margin-right:auto">
                    @if ($atual?->situacao === 'concluido')Salvar agora cria a versão {{ $atual->versao + 1 }}. @endif
                    Ao concluir: {{ ['em_revisao' => 'vai para revisão', 'em_aprovacao' => 'vai para aprovação', 'aguardando_assinatura' => 'vai para assinatura', 'concluido' => 'a peça é concluída'][$destino] }}.
                </span>
                <button class="btn btn-s" type="submit" name="acao" value="rascunho">Salvar rascunho</button>
                <button class="btn btn-p" type="submit" name="acao" value="concluir" onclick="return confirm('Concluir e encaminhar? Depois disso a peça não pode ser editada sem devolução.')"><x-icone nome="ok" />Concluir</button>
            </div>
        @else
            <p class="small muted" style="margin-top:12px">Somente leitura agora: a peça está em tramitação, depende de outras, é feita por outro responsável ou o processo já passou desta etapa.</p>
        @endif
    </form>

    @if ($podeDecidir)
        <section class="card">
            <h2>{{ $atual->situacao === 'em_revisao' ? 'Revisão' : 'Aprovação' }}</h2>
            <p class="hint">Quem fez a peça não pode revisá-la nem aprová-la, e quem revisou não aprova. Ao devolver, informe o que ajustar.</p>
            <form method="POST" action="{{ route('documentos.decidir', [$processo, $tipo]) }}" class="form-grid">
                @csrf
                <div class="campo full"><label for="motivo">Motivo (obrigatório para devolver)</label><textarea id="motivo" name="motivo" class="input" rows="3"></textarea></div>
                <div class="form-acoes full"><button class="btn btn-s" name="acao" value="devolver" type="submit">Devolver para ajustes</button><button class="btn btn-p" name="acao" value="aprovar" type="submit"><x-icone nome="ok" />{{ $atual->situacao === 'em_revisao' ? 'Marcar como revisada' : 'Aprovar' }}</button></div>
            </form>
        </section>
    @endif

    @if ($atual && ($etapa?->exige_assinatura || $atual->assinaturas->isNotEmpty()))
        <section class="card">
            <h2>Assinaturas</h2>
            <div class="list">
                @foreach ($assinantes as $u)
                    @php($a = $atual->assinaturas->firstWhere('user_id', $u->id))
                    <div class="li"><span class="chip {{ $a ? 'c-navy' : 'c-warn' }}"><x-icone nome="{{ $a ? 'ok' : 'relogio' }}" /></span>
                        <div>{{ $u->name }}<div class="meta">@if ($a){{ $a->perfil }} · assinou em {{ $a->assinado_em->format('d/m/Y H:i') }} · código {{ \App\Licitacao\Fluxo\Assinaturas::formatarCodigo($a->codigo) }}@else aguardando assinatura @endif</div></div></div>
                @endforeach
                @foreach ($atual->assinaturas->whereNotIn('user_id', $assinantes->keys()) as $a)
                    <div class="li"><span class="chip c-navy"><x-icone nome="ok" /></span><div>{{ $a->nome }}<div class="meta">{{ $a->perfil }} · assinou em {{ $a->assinado_em->format('d/m/Y H:i') }} · código {{ \App\Licitacao\Fluxo\Assinaturas::formatarCodigo($a->codigo) }}</div></div></div>
                @endforeach
            </div>
            @if ($podeAssinar)
                <form method="POST" action="{{ route('documentos.assinar', [$processo, $tipo]) }}" class="form-grid" style="margin-top:14px">
                    @csrf
                    <div class="campo"><label for="senha">Confirme a sua senha para assinar</label><input id="senha" type="password" name="senha" class="input" autocomplete="current-password" required>
                        <span class="ajuda">A assinatura grava a impressão digital (hash) da peça. Se o texto mudar depois, a assinatura deixa de valer.</span></div>
                    <div class="btns"><button class="btn btn-p" type="submit"><x-icone nome="cadeado" />Assinar</button></div>
                </form>
            @endif
            @if ($atual->assinaturas->isNotEmpty())
                <p style="margin-top:12px"><a class="btn btn-s btn-sm" href="{{ route('documentos.folha', [$processo, $tipo]) }}" target="_blank"><x-icone nome="download" tamanho="16" />Folha de assinaturas (imprimir ou PDF)</a></p>
            @endif
        </section>
    @endif

    @if ($versoes->count() > 1)
        <section class="card">
            <h2>Versões</h2>
            <div class="list">
                @foreach ($versoes as $v)
                    <div class="li"><b>v{{ $v->versao }}</b><div>{{ $situacoes[$v->situacao] ?? $v->situacao }} · {{ $v->autor?->name }}<div class="meta">{{ ($v->concluido_em ?? $v->updated_at)->format('d/m/Y H:i') }}@if ($v->hash_conteudo) · hash {{ substr($v->hash_conteudo, 0, 16) }}... @endif</div></div></div>
                @endforeach
            </div>
        </section>
    @endif
</x-layouts.app>
