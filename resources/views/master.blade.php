<x-layouts.app titulo="Painel master">
    <div>
        <h1 class="h1">Painel master NexMind</h1>
        <p class="sub">Visão operacional de todos os órgãos. Mostra contagens e saúde, nunca o conteúdo dos processos.</p>
    </div>
    <div class="kpis">
        <div class="card kpi"><span class="lbl">Órgãos</span><span class="val">{{ $linhas->count() }}</span><span class="foot">{{ $linhas->where('orgao.ativo', true)->count() }} ativos</span></div>
        <div class="card kpi"><span class="lbl">Trilha de auditoria</span><span class="val">{{ $cadeiaIntegra ? 'Íntegra' : 'Violada' }}</span><span class="foot">{{ number_format($registros, 0, ',', '.') }} registros encadeados por hash @unless ($cadeiaIntegra) · primeiro problema no registro {{ $quebra }}@endunless</span></div>
        <div class="card kpi"><span class="lbl">Catálogo</span><span class="val">{{ number_format($catalogo['M'] + $catalogo['S'], 0, ',', '.') }}</span><span class="foot">{{ number_format($catalogo['M'], 0, ',', '.') }} CATMAT · {{ number_format($catalogo['S'], 0, ',', '.') }} CATSER</span></div>
    </div>
    <section class="card"><h2>Órgãos</h2>
        <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Órgão</th><th>PNCP</th><th class="num">Usuários</th><th class="num">Sem 2FA</th><th class="num">Processos em andamento</th><th class="num">Contratos vigentes</th><th class="num">Erros PNCP</th><th>Última atividade</th></tr></thead><tbody>
            @foreach ($linhas as $l)
                <tr><td><b>{{ $l['orgao']->sigla }}</b>@unless ($l['orgao']->ativo) <span class="b b-mute">inativo</span>@endunless @if ($l['orgao']->treinamento) <span class="b b-warn">treinamento{{ $l['orgao']->treinamento_expira_em ? ' até '.$l['orgao']->treinamento_expira_em->format('d/m') : '' }}</span>@endif<div class="small muted">{{ $l['orgao']->nome }}</div></td>
                    <td>{{ $l['orgao']->pncp_ambiente === 'producao' ? 'Produção' : 'Homologação' }}</td>
                    <td class="num">{{ $l['ativos'] }}</td><td class="num">@if ($l['sem_2fa'])<span class="b b-warn">{{ $l['sem_2fa'] }}</span>@else 0 @endif</td>
                    <td class="num">{{ $l['processos'] }}</td><td class="num">{{ $l['contratos'] }}</td><td class="num">@if ($l['pncp_erros'])<span class="b b-err">{{ $l['pncp_erros'] }}</span>@else 0 @endif</td>
                    <td class="small">{{ $l['ultima'] ? \Illuminate\Support\Carbon::parse($l['ultima'])->format('d/m/Y H:i') : 'sem atividade' }}</td></tr>
            @endforeach
        </tbody></table></div>
    </section>

    <section class="card">
        <h2>Turmas de treinamento</h2>
        <p class="hint">Órgão fictício com um acesso por perfil e processos de exemplo. Nada vai ao PNCP, nenhum alerta sai por e-mail e o acesso termina sozinho no prazo.</p>

        @if (session('acessos_treinamento'))
            <div class="flash flash-aviso" role="alert"><x-icone nome="cadeado" /><span>Anote ou copie agora: as senhas não aparecem de novo. Peça a cada participante que troque a senha no primeiro acesso.</span></div>
            <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Perfil</th><th>Nome</th><th>E-mail</th><th>Senha</th></tr></thead><tbody>
                @foreach (session('acessos_treinamento') as $a)
                    <tr><td>{{ $a['perfil'] }}</td><td>{{ $a['nome'] }}</td><td><code>{{ $a['email'] }}</code></td><td><code>{{ $a['senha'] }}</code></td></tr>
                @endforeach
            </tbody></table></div>
        @endif

        <form method="POST" action="{{ route('treinamento.store') }}" class="form-grid" style="margin-top:12px">
            @csrf
            <x-campo nome="turma" rotulo="Nome da turma" ajuda="Até 8 letras ou números, por exemplo 01 ou 2026A." :obrigatorio="true" maxlength="8" />
            <x-campo nome="dias" rotulo="Dias de acesso" tipo="number" :valor="config('nexplangov.treinamento.dias')" :obrigatorio="true" min="1" max="180" />
            <div class="full form-acoes"><button class="btn btn-p" type="submit"><x-icone nome="mais" />Preparar turma</button></div>
        </form>

        @php($turmas = $linhas->pluck('orgao')->where('treinamento', true))
        @if ($turmas->isNotEmpty())
            <div class="tbl-wrap" style="margin-top:16px"><table class="tbl"><thead><tr><th>Turma</th><th>Acesso até</th><th>Situação</th><th class="acoes"></th></tr></thead><tbody>
                @foreach ($turmas as $t)
                    <tr><td><b>{{ $t->sigla }}</b></td><td>{{ $t->treinamento_expira_em?->format('d/m/Y') }}</td><td>{!! $t->ativo ? '<span class="b b-ok">ativa</span>' : '<span class="b b-mute">encerrada</span>' !!}</td>
                        <td class="acoes">@if ($t->ativo)<form method="POST" action="{{ route('treinamento.encerrar', $t) }}" onsubmit="return confirm('Encerrar a turma {{ $t->sigla }}? Os acessos são desativados; nada é apagado.')">@csrf<button class="btn btn-g btn-sm" type="submit">Encerrar</button></form>@endif</td></tr>
                @endforeach
            </tbody></table></div>
        @endif
    </section>
</x-layouts.app>
