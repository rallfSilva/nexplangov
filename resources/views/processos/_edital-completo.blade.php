{{-- Gerar edital completo (art. 25): monta a minuta com os dados do processo e junta os anexos num caderno único. --}}
@php($dados = $edital['dados'])
@php($v = fn (string $c) => old("edital.$c", $dados[$c] ?? null))
@php($gerado = session('edital_gerado'))
<section class="card" id="edital-completo">
    <h2>Gerar edital completo</h2>
    <p class="hint">O sistema escolhe o modelo da modalidade e preenche o edital com os dados do processo, do ETP, do Termo de Referência e da minuta do contrato: data e local da sessão, prazo para assinar o contrato, garantia, habilitação e o benefício às ME/EPP de cada item. Confira os dados abaixo. O texto vem para a tela sem ser salvo, e continua sendo minuta para a sua revisão e a da assessoria jurídica <span class="lei">art. 53</span>.</p>

    @if ($gerado)
        <div class="aviso-lei" role="status">
            <b>Edital montado a partir do modelo "{{ $gerado['modelo'] }}".</b>
            @if ($gerado['pendentes'] === []) Nenhum trecho ficou entre colchetes. Revise o texto, salve e conclua.
            @else Falta completar {{ count($gerado['pendentes']) }} trecho(s), marcados entre colchetes no texto:
                <ul style="margin:6px 0 0 18px">@foreach ($gerado['pendentes'] as $p)<li>{{ $p }}</li>@endforeach</ul>
            @endif
        </div>
        @foreach ($gerado['alertas'] as $a)
            <div class="aviso-lei" style="border-color:#E0A458;background:#FFF7EC;color:#7A3B0B">{{ $a }}</div>
        @endforeach
    @endif

    @if ($podeEditar && $edital['modelos']->isNotEmpty())
        <form method="POST" action="{{ route('edital.gerar', $processo) }}" class="form-grid" id="form-edital">
            @csrf
            <div class="campo"><label for="e-modelo">Modelo de edital</label>
                <select id="e-modelo" name="modelo_id" class="input">@foreach ($edital['modelos'] as $m)<option value="{{ $m->id }}" @selected((int) old('modelo_id', $edital['modelos']->first()->id) === $m->id)>{{ $m->titulo }}</option>@endforeach</select>
                <span class="ajuda">O primeiro é o recomendado para a modalidade e a categoria do processo.</span></div>
            <div class="campo"><label for="e-sessao">Data e hora da sessão pública</label>
                <input id="e-sessao" type="datetime-local" name="edital[sessao_em]" class="input" value="{{ $v('sessao_em') }}">
                <span class="ajuda">Vem da abertura das propostas do processo. Respeite o prazo mínimo de divulgação <span class="lei">art. 55</span>.</span></div>
            <div class="campo"><label for="e-local">{{ in_array($processo->modalidade, [5, 7], true) ? 'Endereço da sessão presencial' : 'Plataforma eletrônica' }}</label>
                <input id="e-local" type="text" name="edital[local]" class="input" maxlength="300" value="{{ $v('local') }}" placeholder="{{ in_array($processo->modalidade, [5, 7], true) ? 'Rua, número, sala' : 'Nome e endereço da plataforma' }}"></div>
            <div class="campo"><label for="e-prazo">Prazo para o vencedor assinar o contrato (dias úteis)</label>
                <input id="e-prazo" type="number" min="1" max="30" name="edital[prazo_assinatura]" class="input" value="{{ $v('prazo_assinatura') }}">
                <span class="ajuda">Padrão do órgão nos parâmetros; prorrogável uma vez <span class="lei">art. 90, § 1º</span>.</span></div>
            <div class="campo"><label for="e-garantia">Garantia de execução (% do valor do contrato)</label>
                <input id="e-garantia" type="number" min="0" max="30" step="0.01" name="edital[garantia_pct]" class="input" value="{{ $v('garantia_pct') }}" placeholder="0 se não exigir">
                <span class="ajuda">Até 5%, ou até 10% com justificativa <span class="lei">arts. 96 a 98</span>. Vem da minuta do contrato quando ela já define.</span></div>
            <div class="campo"><label for="e-garantia-just">Justificativa da garantia acima de 5%</label>
                <input id="e-garantia-just" type="text" name="edital[garantia_justificativa]" class="input" maxlength="1000" value="{{ $v('garantia_justificativa') }}"></div>
            <div class="campo full"><label for="e-hab">Exigências de habilitação desta licitação</label>
                <textarea id="e-hab" name="edital[habilitacao]" class="input" rows="3" maxlength="4000">{{ $v('habilitacao') }}</textarea>
                <span class="ajuda">Vem do item h) do Termo de Referência. Exija só o proporcional ao objeto <span class="lei">arts. 62 a 70</span>.</span></div>
            @if ($edital['engenharia'])
                <div class="campo"><label for="e-regime">Regime de execução</label>
                    <select id="e-regime" name="edital[regime]" class="input"><option value="">A definir</option>@foreach (\App\Licitacao\Regras\MontagemEdital::REGIMES as $k => $r)<option value="{{ $k }}" @selected($v('regime') === $k)>{{ ucfirst($r) }}</option>@endforeach</select>
                    <span class="ajuda"><span class="lei">art. 46</span></span></div>
                <div class="campo"><label for="e-visita">Visita técnica</label>
                    <select id="e-visita" name="edital[visita]" class="input"><option value="">A definir</option><option value="facultativa" @selected($v('visita') === 'facultativa')>Facultativa</option><option value="obrigatoria" @selected($v('visita') === 'obrigatoria')>Obrigatória (com justificativa)</option></select>
                    <span class="ajuda">Pode ser trocada por declaração do licitante <span class="lei">art. 63, § 2º</span>.</span></div>
                <div class="campo full"><label for="e-visita-just">Justificativa da visita obrigatória</label>
                    <input id="e-visita-just" type="text" name="edital[visita_justificativa]" class="input" maxlength="1000" value="{{ $v('visita_justificativa') }}"></div>
            @endif
            <p class="small muted full" style="margin:0">Benefício às ME/EPP: sai item a item, pelo que está cadastrado na aba Itens do processo <span class="lei">art. 4º</span>.</p>
            <div class="form-acoes full">
                @if ($edital['podeIa'])
                    <button type="button" class="btn btn-s" id="edital-ia"><x-icone nome="liga" />Propor o que falta com a NexMind IA</button>
                @endif
                <button class="btn btn-p" type="submit"><x-icone nome="ok" />Gerar edital completo</button>
            </div>
            <div id="edital-ia-msg" class="small" aria-live="polite"></div>
        </form>
    @endif

    <p style="margin-top:12px"><a class="btn btn-s btn-sm" href="{{ route('edital.caderno', $processo) }}" target="_blank"><x-icone nome="download" tamanho="16" />Caderno do edital em PDF (edital e anexos I a IV)</a>
        <span class="small muted">Usa a última versão salva de cada peça e lista na capa o que ainda falta.</span></p>
</section>

@if ($podeEditar && $edital['podeIa'])
    <script>
    (() => {
        const b = document.getElementById('edital-ia');
        const msg = document.getElementById('edital-ia-msg');
        b?.addEventListener('click', async () => {
            const form = document.getElementById('form-edital');
            const dados = {};
            form.querySelectorAll('[name^="edital["]').forEach(el => { dados[el.name.slice(7, -1)] = el.value; });
            b.disabled = true; msg.textContent = 'A NexMind IA está analisando o ETP e o Termo de Referência...';
            try {
                const r = await fetch(@json(route('edital.ia', $processo)), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) }, body: JSON.stringify({ edital: dados }) });
                const j = await r.json().catch(() => ({ erro: 'Resposta inválida.' }));
                if (!r.ok) throw new Error(j.erro || j.message || 'Não foi possível usar a IA agora.');
                let n = 0;
                for (const [k, valor] of Object.entries(j.proposta || {})) {
                    const el = form.querySelector(`[name="edital[${k}]"]`);
                    if (el && el.value.trim() === '' && valor !== null) { el.value = valor; el.style.borderColor = '#6CB1DA'; n++; }
                }
                msg.textContent = n ? `${n} campo(s) vazio(s) preenchido(s) pela IA, destacados em azul. ${j.aviso}` : 'A IA não teve base para propor nada além do que já está preenchido.';
            } catch (e) { msg.textContent = e.message; }
            b.disabled = false;
        });
    })();
    </script>
@endif
