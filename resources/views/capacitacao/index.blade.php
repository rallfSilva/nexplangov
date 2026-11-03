<x-layouts.app titulo="Capacitação">
    @php($validade = config('nexplangov.capacitacao_validade_meses'))
    <div>
        <h1 class="h1">Capacitação dos agentes</h1>
        <p class="sub">Quem atua em licitações e contratos precisa de qualificação compatível, preferencialmente por escola de governo <span class="lei">art. 7º, II, e art. 173</span>. Um curso conta como atualizado por {{ $validade }} meses.</p>
    </div>

    @if ($gerencia)
        <section class="card"><h2>Situação por agente</h2>
            <div class="list">
                @forelse ($usuarios as $u)
                    @php($ultimo = ($porUsuario[$u->id] ?? collect())->first())
                    <div class="li"><span class="chip {{ $ultimo?->vigente() ? 'c-teal' : 'c-warn' }}"><x-icone nome="{{ $ultimo?->vigente() ? 'ok' : 'alerta' }}" /></span>
                        <div>{{ $u->name }}<div class="meta">@if ($ultimo)Último curso: {{ $ultimo->curso }} em {{ $ultimo->concluida_em->format('d/m/Y') }}@else Nenhum curso registrado @endif</div></div>
                        <span class="r small">{{ ($porUsuario[$u->id] ?? collect())->sum('carga_horaria') }} h</span></div>
                @empty
                    <div class="li muted">Nenhum agente com perfil de contratação neste órgão.</div>
                @endforelse
            </div>
        </section>
    @endif

    <section class="card"><h2>Registrar curso concluído</h2>
        <form method="POST" action="{{ route('capacitacao.store') }}" class="form-grid">@csrf
            @if ($gerencia)
                <x-campo nome="user_id" rotulo="Agente" obrigatorio><select id="campo-user_id" name="user_id" class="input">@foreach ($usuarios as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></x-campo>
            @else
                <input type="hidden" name="user_id" value="{{ auth()->id() }}">
            @endif
            <x-campo nome="curso" rotulo="Curso" obrigatorio />
            <x-campo nome="instituicao" rotulo="Instituição" obrigatorio />
            <x-campo nome="tema" rotulo="Tema" obrigatorio><select id="campo-tema" name="tema" class="input">@foreach (\App\Models\Capacitacao::TEMAS as $k => $r)<option value="{{ $k }}">{{ $r }}</option>@endforeach</select></x-campo>
            <x-campo nome="carga_horaria" rotulo="Carga horária (horas)" tipo="number" obrigatorio />
            <x-campo nome="concluida_em" rotulo="Concluído em" tipo="date" obrigatorio />
            <x-campo nome="certificado" rotulo="Número ou código do certificado" />
            <div class="campo"><label class="check"><input type="hidden" name="escola_governo" value="0"><input type="checkbox" name="escola_governo" value="1"> Escola de governo</label></div>
            <div class="form-acoes full"><button class="btn btn-p" type="submit">Registrar</button></div>
        </form>
    </section>

    <section class="card"><h2>Cursos registrados</h2>
        <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Agente</th><th>Curso</th><th>Tema</th><th class="num">Horas</th><th>Concluído</th></tr></thead><tbody>
            @forelse ($cursos as $c)
                <tr><td>{{ $c->usuario->name }}</td><td><b>{{ $c->curso }}</b><div class="small muted">{{ $c->instituicao }}@if ($c->escola_governo) · escola de governo @endif @if ($c->certificado) · certificado {{ $c->certificado }}@endif</div></td>
                    <td class="small">{{ \App\Models\Capacitacao::TEMAS[$c->tema] }}</td><td class="num">{{ $c->carga_horaria }}</td><td>{{ $c->concluida_em->format('d/m/Y') }}@unless ($c->vigente()) <span class="b b-mute">desatualizado</span>@endunless</td></tr>
            @empty
                <tr><td colspan="5" class="muted">Nenhum curso registrado.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>
</x-layouts.app>
