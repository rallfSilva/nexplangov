<x-layouts.app :titulo="'Processo sancionador '.$ps->numero">
    @php($conduz = auth()->user()->can('sancoes.conduzir'))
    @php($autoridade = auth()->user()->can('processos.autorizar'))
    @php($juridico = auth()->user()->can('processos.parecer'))
    @php($tipos = \App\Models\SancaoFornecedor::TIPOS)
    @php($rot = fn () => 'c'.\Illuminate\Support\Str::random(8))
    <div>
        <a href="{{ route('sancoes.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Processos sancionadores</a>
        <h1 class="h1" style="margin-top:8px">{{ $ps->numero }} <span class="b {{ $ps->situacao === 'definitivo' ? 'b-err' : (in_array($ps->situacao, ['arquivado', 'reabilitado']) ? 'b-mute' : 'b-info') }}" style="vertical-align:middle">{{ \App\Models\ProcessoSancionador::SITUACOES[$ps->situacao] }}</span></h1>
        <p class="sub">{{ $ps->fornecedor->razao_social }} · {{ $ps->fornecedor->documento }}@if ($ps->contrato) · contrato {{ $ps->contrato->identificacao() }}@endif</p>
    </div>

    <section class="card"><h2>Instauração <span class="lei">arts. 155 e 158</span></h2>
        <dl class="dl">
            <div><dt>Infrações</dt><dd>@foreach ($ps->infracoes as $i)<div>art. 155, <b>{{ $i }}</b>: {{ \App\Licitacao\Regras\Sancoes::INFRACOES[$i] }}</div>@endforeach</dd></div>
            <div><dt>Fatos</dt><dd style="white-space:pre-line">{{ $ps->fatos }}</dd></div>
            <div><dt>Sanção em tese</dt><dd>{{ $tipos[$ps->sancao_proposta] }}@if ($ps->justificativa_agravamento)<div class="small muted">{{ $ps->justificativa_agravamento }}</div>@endif</dd></div>
            @if ($comissao->isNotEmpty())<div><dt>Comissão</dt><dd>{{ $comissao->join(', ') }}</dd></div>@endif
        </dl>
        @if ($conduz && $ps->situacao === 'instaurado')
            <h3 style="margin-top:14px">Intimar o fornecedor para defesa (15 dias úteis) <span class="lei">arts. 157 e 158</span></h3>
            <form method="POST" action="{{ route('sancoes.intimar', $ps) }}" class="form-grid">@csrf
                <x-campo nome="intimado_em" rotulo="Data da intimação" tipo="date" obrigatorio :valor="today()->toDateString()" />
                <x-campo nome="meio" rotulo="Meio (e-mail com confirmação de leitura, carta com AR, publicação)" obrigatorio />
                <div class="form-acoes full"><button class="btn btn-p" type="submit">Registrar intimação e gerar a notificação</button></div>
            </form>
        @endif
    </section>

    @if ($ps->intimado_em)
        <section class="card"><h2>Defesa <span class="lei">arts. 157 e 158</span></h2>
            <p>Intimado em {{ $ps->intimado_em->format('d/m/Y') }} ({{ $ps->intimacao_meio }}). Prazo de defesa até <b>{{ $ps->prazo_defesa->format('d/m/Y') }}</b>.
                @if ($ps->intimacao_caminho)<a class="btn btn-s btn-sm" href="{{ route('sancoes.arquivo', [$ps, 'intimacao']) }}"><x-icone nome="download" />Notificação (PDF)</a>@endif</p>
            @if ($ps->defesa)
                <div class="aviso-lei"><b>Defesa recebida em {{ $ps->defesa_em->format('d/m/Y') }}</b>@if ($ps->provas_requeridas) · com pedido de provas @endif<br><span style="white-space:pre-line">{{ $ps->defesa }}</span>
                    @if ($ps->defesa_caminho)<div style="margin-top:6px"><a class="btn btn-s btn-sm" href="{{ route('sancoes.arquivo', [$ps, 'defesa']) }}"><x-icone nome="download" />Documento da defesa</a> <span class="small muted">SHA-256 {{ $ps->defesa_hash }}</span></div>@endif</div>
            @elseif ($ps->situacao === 'defesa')
                @if ($ps->prazo_defesa->lt(today()))<p class="small"><b>O prazo terminou sem defesa.</b> A autoridade pode decidir.</p>@endif
                @if ($conduz)
                    <form method="POST" action="{{ route('sancoes.defesa', $ps) }}" enctype="multipart/form-data" class="form-grid">@csrf
                        <x-campo nome="recebida_em" rotulo="Recebida em" tipo="date" obrigatorio :valor="today()->toDateString()" />
                        <x-campo nome="arquivo" rotulo="Documento da defesa (PDF ou imagem)" tipo="file" />
                        <div class="campo full"><label for="campo-texto-defesa">Resumo da defesa <span aria-hidden="true" style="color:#9F1D1D">*</span></label><textarea id="campo-texto-defesa" name="texto" class="input" rows="3" required>{{ old('texto') }}</textarea></div>
                        <div class="campo full"><label style="display:flex;gap:8px;font-weight:400"><input type="checkbox" name="provas" value="1"> O fornecedor pediu a produção de provas (abre instrução e alegações finais, art. 158, § 2º)</label></div>
                        <div class="form-acoes full"><button class="btn btn-p" type="submit">Registrar defesa</button></div>
                    </form>
                @endif
            @endif

            @if ($ps->situacao === 'instrucao' && $conduz)
                <h3 style="margin-top:14px">Encerrar a instrução e intimar para alegações finais (15 dias úteis)</h3>
                <form method="POST" action="{{ route('sancoes.instrucao', $ps) }}" class="form-grid">@csrf
                    <x-campo nome="intimado_em" rotulo="Intimação para alegações em" tipo="date" obrigatorio :valor="today()->toDateString()" />
                    <div class="campo full"><label for="campo-relatorio">Provas produzidas <span aria-hidden="true" style="color:#9F1D1D">*</span></label><textarea id="campo-relatorio" name="relatorio" class="input" rows="3" required></textarea></div>
                    <div class="form-acoes full"><button class="btn btn-p" type="submit">Encerrar instrução</button></div>
                </form>
            @endif
            @if ($ps->instrucao)<p class="small" style="margin-top:10px"><b>Instrução:</b> {{ $ps->instrucao }}<br>Alegações finais até {{ $ps->prazo_alegacoes->format('d/m/Y') }}.</p>@endif
            @if ($ps->alegacoes)<p class="small"><b>Alegações finais ({{ $ps->alegacoes_em->format('d/m/Y') }}):</b> {{ $ps->alegacoes }}</p>@endif
            @if ($ps->situacao === 'alegacoes' && $conduz)
                <form method="POST" action="{{ route('sancoes.alegacoes', $ps) }}" class="form-grid">@csrf
                    <x-campo nome="recebidas_em" rotulo="Recebidas em" tipo="date" obrigatorio :valor="today()->toDateString()" />
                    <div class="campo full"><label for="campo-texto-alegacoes">Resumo das alegações finais <span aria-hidden="true" style="color:#9F1D1D">*</span></label><textarea id="campo-texto-alegacoes" name="texto" class="input" rows="3" required></textarea></div>
                    <div class="form-acoes full"><button class="btn btn-p" type="submit">Registrar alegações</button></div>
                </form>
            @endif
        </section>
    @endif

    @if ($ps->intimado_em)
        <section class="card"><h2>Análise jurídica <span class="lei">art. 156, § 6º</span></h2>
            @if ($ps->parecer_juridico)
                <div class="aviso-lei" style="white-space:pre-line">{{ $ps->parecer_juridico }}</div><p class="small muted">Registrado em {{ $ps->parecer_em->format('d/m/Y H:i') }}.</p>
            @elseif ($juridico && in_array($ps->situacao, ['defesa', 'instrucao', 'alegacoes', 'decisao']))
                <p class="hint">Obrigatória antes da declaração de inidoneidade; recomendada nas demais.</p>
                <form method="POST" action="{{ route('sancoes.parecer', $ps) }}">@csrf
                    <div class="campo "><label for="campo-texto-parecer">Parecer <span aria-hidden="true" style="color:#9F1D1D">*</span></label><textarea id="campo-texto-parecer" name="texto" class="input" rows="4" required></textarea></div>
                    <button class="btn btn-p" type="submit" style="margin-top:8px">Registrar parecer</button></form>
            @else
                <p class="small muted">Sem parecer.</p>
            @endif
        </section>
    @endif

    @php($podeDecidir = $ps->situacao === 'decisao' || ($ps->situacao === 'defesa' && $ps->prazo_defesa->lt(today())) || ($ps->situacao === 'alegacoes' && $ps->prazo_alegacoes->lt(today())))
    @if ($ps->decidido_em || ($autoridade && $podeDecidir))
        <section class="card"><h2>Decisão <span class="lei">art. 156, § 1º</span></h2>
            @if ($ps->decidido_em)
                <dl class="dl">
                    <div><dt>Resultado</dt><dd>{{ $ps->resultado === 'arquivado' ? 'Arquivado, sem sanção' : $tipos[$ps->sancao_tipo] }}@if ($ps->duracao_meses) · {{ $ps->duracao_meses }} meses @endif @if ($ps->multa_valor !== null) · multa de {{ reais($ps->multa_valor) }} sobre {{ reais($ps->base_calculo) }}@endif</dd></div>
                    @if ($ps->dosimetria)<div><dt>Dosimetria</dt><dd style="white-space:pre-line">{{ $ps->dosimetria }}</dd></div>@endif
                    <div><dt>Fundamentos</dt><dd style="white-space:pre-line">{{ $ps->decisao }}</dd></div>
                    <div><dt>Decidido</dt><dd>{{ $ps->decidido_em->format('d/m/Y') }} por {{ $ps->decisor?->name }}@if ($ps->prazo_recurso) · {{ $ps->sancao_tipo === 'inidoneidade' ? 'pedido de reconsideração' : 'recurso' }} até {{ $ps->prazo_recurso->format('d/m/Y') }}@endif</dd></div>
                </dl>
            @else
                <form method="POST" action="{{ route('sancoes.decidir', $ps) }}" class="form-grid">@csrf
                    <div class="campo "><label for="campo-resultado">Resultado <span aria-hidden="true" style="color:#9F1D1D">*</span></label><select id="campo-resultado" name="resultado" class="input"><option value="aplicada">Aplicar sanção</option><option value="arquivado">Arquivar (sem sanção)</option></select></div>
                    <div class="campo "><label for="campo-sancao_tipo">Sanção</label><select id="campo-sancao_tipo" name="sancao_tipo" class="input">@foreach ($tipos as $v => $r)<option value="{{ $v }}" @selected($ps->sancao_proposta === $v)>{{ $r }}</option>@endforeach</select></div>
                    <x-campo nome="duracao_meses" rotulo="Duração em meses (impedimento até 36; inidoneidade de 36 a 72)" tipo="number" min="1" max="72" />
                    <x-campo nome="base_calculo" rotulo="Valor do contrato ou da contratação (base da multa)" inputmode="decimal" :valor="$ps->contrato?->valor_global" />
                    <x-campo nome="multa_valor" rotulo="Multa (0,5% a 30%; pode somar-se a outra sanção)" inputmode="decimal" />
                    <div class="campo full"><label for="campo-dosimetria">Dosimetria: natureza e gravidade, peculiaridades, agravantes e atenuantes, danos, programa de integridade</label><textarea id="campo-dosimetria" name="dosimetria" class="input" rows="3"></textarea></div>
                    <div class="campo full"><label for="campo-decisao">Fundamentos da decisão, com análise da defesa <span aria-hidden="true" style="color:#9F1D1D">*</span></label><textarea id="campo-decisao" name="decisao" class="input" rows="4" required></textarea></div>
                    <div class="form-acoes full"><button class="btn btn-p" type="submit">Registrar decisão</button></div>
                </form>
            @endif
        </section>
    @endif

    @if (in_array($ps->situacao, ['decidido', 'recurso']) || $ps->recurso_em)
        <section class="card"><h2>{{ $ps->sancao_tipo === 'inidoneidade' ? 'Pedido de reconsideração' : 'Recurso' }} <span class="lei">{{ $ps->sancao_tipo === 'inidoneidade' ? 'art. 167' : 'art. 166' }} · efeito suspensivo, art. 168</span></h2>
            @if ($ps->recurso_em)
                <div class="aviso-lei"><b>Recebido em {{ $ps->recurso_em->format('d/m/Y') }}</b><br><span style="white-space:pre-line">{{ $ps->recurso_texto }}</span></div>
                @if ($ps->reconsideracao_texto)<p class="small"><b>{{ $ps->recurso_resultado === 'provido' && ! $ps->recurso_decidido_por ? 'Reconsiderada' : 'Mantida' }} por quem decidiu:</b> {{ $ps->reconsideracao_texto }}</p>@endif
                @if ($ps->recurso_resultado)<p class="small"><b>{{ \App\Models\ProcessoSancionador::RESULTADOS_RECURSO[$ps->recurso_resultado] }}</b> em {{ $ps->recurso_decidido_em?->format('d/m/Y') }}: {{ $ps->recurso_decisao }}</p>@endif
            @elseif ($ps->situacao === 'decidido' && $conduz && ! $ps->recurso_decidido_em)
                <form method="POST" action="{{ route('sancoes.recurso', $ps) }}" class="form-grid">@csrf
                    <x-campo nome="recebido_em" rotulo="Recebido em" tipo="date" obrigatorio :valor="today()->toDateString()" />
                    <div class="campo full"><label for="campo-texto-recurso">Resumo das razões <span aria-hidden="true" style="color:#9F1D1D">*</span></label><textarea id="campo-texto-recurso" name="texto" class="input" rows="3" required></textarea></div>
                    <div class="form-acoes full"><button class="btn btn-s" type="submit">Registrar recurso</button></div>
                </form>
            @endif

            @if ($ps->situacao === 'recurso' && $autoridade)
                @if ($ps->recurso_meio === 'recurso' && ! $ps->reconsiderado_por)
                    <h3 style="margin-top:12px">Reconsiderar ou manter (até {{ $ps->prazo_reconsideracao->format('d/m/Y') }})</h3>
                    <form method="POST" action="{{ route('sancoes.reconsiderar', $ps) }}">@csrf
                        <div class="campo "><label for="campo-texto-reconsiderar">Fundamentos <span aria-hidden="true" style="color:#9F1D1D">*</span></label><textarea id="campo-texto-reconsiderar" name="texto" class="input" rows="3" required></textarea></div>
                        <div class="btns" style="margin-top:8px"><button class="btn btn-s" name="acao" value="manter">Manter e encaminhar à autoridade superior</button><button class="btn btn-s" name="acao" value="reconsiderar">Reconsiderar (afastar a sanção)</button></div>
                    </form>
                @else
                    <h3 style="margin-top:12px">Decidir {{ $ps->recurso_meio === 'reconsideracao' ? 'o pedido de reconsideração' : 'o recurso (autoridade superior)' }} até {{ $ps->prazo_decisao_recurso?->format('d/m/Y') }}</h3>
                    <form method="POST" action="{{ route('sancoes.recurso.decidir', $ps) }}" class="form-grid">@csrf
                        <div class="campo "><label for="campo-resultado-recurso">Resultado <span aria-hidden="true" style="color:#9F1D1D">*</span></label><select id="campo-resultado-recurso" name="resultado" class="input">@foreach (\App\Models\ProcessoSancionador::RESULTADOS_RECURSO as $v => $r)<option value="{{ $v }}">{{ $r }}</option>@endforeach</select></div>
                        <div class="campo "><label for="campo-sancao_tipo-recurso">Nova sanção (se provido em parte)</label><select id="campo-sancao_tipo-recurso" name="sancao_tipo" class="input">@foreach ($tipos as $v => $r)<option value="{{ $v }}" @selected($ps->sancao_tipo === $v)>{{ $r }}</option>@endforeach</select></div>
                        <x-campo nome="duracao_meses" rotulo="Nova duração em meses" tipo="number" min="1" max="72" :valor="$ps->duracao_meses" />
                        <x-campo nome="multa_valor" rotulo="Nova multa" inputmode="decimal" :valor="$ps->multa_valor" />
                        <input type="hidden" name="base_calculo" value="{{ $ps->base_calculo }}">
                        <div class="campo full"><label for="campo-dosimetria-recurso">Nova dosimetria (se provido em parte)</label><textarea id="campo-dosimetria-recurso" name="dosimetria" class="input" rows="2"></textarea></div>
                        <div class="campo full"><label for="campo-decisao-recurso">Fundamentos <span aria-hidden="true" style="color:#9F1D1D">*</span></label><textarea id="campo-decisao-recurso" name="decisao" class="input" rows="3" required></textarea></div>
                        <div class="form-acoes full"><button class="btn btn-p" type="submit">Decidir</button></div>
                    </form>
                @endif
            @endif
        </section>
    @endif

    @if ($ps->situacao === 'decidido' && $conduz && ($ps->recurso_decidido_em || $ps->prazo_recurso->lt(today())))
        <section class="card"><h2>Tornar a sanção definitiva</h2>
            <p class="hint">{{ $ps->recurso_decidido_em ? 'O recurso foi decidido.' : 'O prazo de recurso terminou sem recurso.' }} A sanção passa a produzir efeitos e entra no cadastro do fornecedor.</p>
            <form method="POST" action="{{ route('sancoes.definitiva', $ps) }}" onsubmit="return confirm('Tornar a sanção definitiva?')">@csrf<button class="btn btn-p" type="submit">Tornar definitiva</button></form>
        </section>
    @endif

    @if (in_array($ps->situacao, ['definitivo', 'reabilitado']))
        <section class="card"><h2>Efeitos e cadastro nacional <span class="lei">arts. 156, 161 e 163</span></h2>
            <dl class="dl">
                <div><dt>Sanção</dt><dd>{{ $tipos[$ps->sancao_tipo] }} · desde {{ $ps->sancao?->inicio?->format('d/m/Y') }}@if ($ps->sancao?->fim) até {{ $ps->sancao->fim->format('d/m/Y') }}@endif
                    @if ($ps->sancao?->abrangencia === 'ente')<div class="small muted">Vale para a administração direta e indireta do ente federativo (art. 156, § 4º).</div>@elseif ($ps->sancao?->abrangencia === 'nacional')<div class="small muted">Vale para todos os entes federativos (art. 156, § 5º).</div>@endif</dd></div>
                <div><dt>{{ $ps->cadastroNacional() }}</dt><dd>
                    @if ($ps->ceis_informado_em)Informado em {{ $ps->ceis_informado_em->format('d/m/Y') }} · {{ $ps->ceis_protocolo }}
                    @else <b @if ($ps->ceisAtrasado()) style="color:#9F1D1D" @endif>Informar até {{ $ps->prazo_ceis->format('d/m/Y') }}</b> (15 dias úteis). Confira na orientação vigente da CGU em qual cadastro lançar.@endif</dd></div>
                @if ($ps->reabilitado_em)<div><dt>Reabilitação</dt><dd>{{ $ps->reabilitado_em->format('d/m/Y') }} · {{ $ps->reabilitacao_fundamento }}</dd></div>@endif
            </dl>
            @if ($conduz && ! $ps->ceis_informado_em)
                <form method="POST" action="{{ route('sancoes.ceis', $ps) }}" class="form-grid" style="margin-top:10px">@csrf
                    <x-campo nome="informado_em" rotulo="Lançado no cadastro em" tipo="date" obrigatorio :valor="today()->toDateString()" />
                    <x-campo nome="protocolo" rotulo="Número ou comprovante do registro" obrigatorio />
                    <div class="form-acoes full"><a class="btn btn-s" href="{{ route('sancoes.ceis.exportar') }}"><x-icone nome="download" />Planilha com os dados</a><button class="btn btn-p" type="submit">Registrar comunicação</button></div>
                </form>
            @endif
            @if ($autoridade && $ps->situacao === 'definitivo' && in_array($ps->sancao_tipo, ['impedimento', 'inidoneidade']))
                <details style="margin-top:12px"><summary class="btn btn-s btn-sm" style="list-style:none">Reabilitação (art. 163)</summary>
                    <form method="POST" action="{{ route('sancoes.reabilitar', $ps) }}" class="form-grid" style="margin-top:10px">@csrf
                        <fieldset class="campo full" style="border:0;padding:0"><legend class="small" style="font-weight:600">Requisitos cumpridos</legend>
                            @foreach (['reparacao' => 'Reparação integral do dano (I)', 'multa_paga' => 'Multa paga (II)', 'condicoes' => 'Condições do ato punitivo cumpridas (IV)', 'parecer' => 'Análise jurídica conclusiva (V)', 'integridade' => 'Programa de integridade (parágrafo único; exigido para os incisos VIII e XII)'] as $v => $r)
                                <label style="display:flex;gap:8px;font-weight:400"><input type="checkbox" name="requisitos[]" value="{{ $v }}"> {{ $r }}</label>
                            @endforeach
                        </fieldset>
                        <div class="campo full"><label for="campo-fundamento">Fundamentos da reabilitação <span aria-hidden="true" style="color:#9F1D1D">*</span></label><textarea id="campo-fundamento" name="fundamento" class="input" rows="3" required></textarea></div>
                        <div class="form-acoes full"><button class="btn btn-p" type="submit">Reabilitar</button></div>
                    </form>
                </details>
            @endif
        </section>
    @endif
</x-layouts.app>
