<x-layouts.app titulo="Parâmetros e permissões">
    <div class="row">
        <div>
            <h1 class="h1">Parâmetros e permissões</h1>
            <p class="sub">Prazos e regras do órgão, dentro dos limites que a lei admite, e permissões extras sem acumular funções incompatíveis <span class="lei">art. 7º, § 1º</span></p>
        </div>
    </div>

    @if ($errors->any())
        <div class="flash flash-erro" role="alert"><x-icone nome="alerta" style="flex:none" /><div>Confira:<ul style="margin:8px 0 0;padding-left:18px">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div></div>
    @endif

    @foreach ($grupos as $grupo => $itens)
        <section class="card">
            <h2>{{ $grupo }}</h2>
            <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Parâmetro</th><th>Valor em uso</th><th>Ajustar</th></tr></thead><tbody>
                @foreach ($itens as $p)
                    @php $atual = is_array($p['atual']) ? implode(', ', $p['atual']) : $p['atual']; $padrao = is_array($p['padrao']) ? implode(', ', $p['padrao']) : $p['padrao']; @endphp
                    <tr id="parametro-{{ $p['chave'] }}">
                        <td><b>{{ $p['rotulo'] }}</b><div class="small muted">De {{ $p['min'] }} a {{ $p['max'] }} {{ $p['unidade'] }} · <span class="lei">{{ $p['base'] }}</span></div>@isset($p['ajuda'])<div class="small muted">{{ $p['ajuda'] }}</div>@endisset</td>
                        <td><b class="valor">{{ $atual }}</b> <span class="small muted">{{ $p['unidade'] }}</span>
                            @if ($p['ajuste'])<div class="small"><span class="b b-info">ajustado pelo órgão</span> padrão: {{ $padrao }}</div><div class="small muted">{{ $p['ajuste']->justificativa }}</div>
                            @else<div class="small muted">padrão do sistema</div>@endif
                        </td>
                        <td>
                            <form method="POST" action="{{ route('parametros.salvar', $p['chave']) }}" class="form-grid" style="gap:6px">@csrf @method('PUT')
                                <label class="sr" for="valor-{{ $p['chave'] }}">Novo valor de {{ $p['rotulo'] }}</label>
                                <input id="valor-{{ $p['chave'] }}" name="valor" class="input" value="{{ $atual }}" maxlength="200" required @error('valor_'.$p['chave']) aria-invalid="true" @enderror>
                                @error('valor_'.$p['chave'])<div class="small" style="color:#9F1D1D;font-weight:600">{{ $message }}</div>@enderror
                                <label class="sr" for="justificativa-{{ $p['chave'] }}">Justificativa</label>
                                <input id="justificativa-{{ $p['chave'] }}" name="justificativa" class="input" placeholder="Justificativa (mínimo 15 caracteres)" minlength="15" maxlength="500" required>
                                <div class="btns"><button class="btn btn-p btn-sm" type="submit">Salvar</button></div>
                            </form>
                            @if ($p['ajuste'])
                                <form method="POST" action="{{ route('parametros.restaurar', $p['chave']) }}" style="margin-top:6px">@csrf @method('DELETE')<button class="btn btn-g btn-sm" type="submit">Voltar ao padrão</button></form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody></table></div>
        </section>
    @endforeach

    <section class="card">
        <h2>Conceder permissão extra</h2>
        <p class="small muted">Para cobrir férias ou acúmulo pontual. Parecer jurídico, autorização da autoridade, aprovação do PCA e administração do sistema só vêm do perfil <span class="lei">art. 53 e art. 71</span>. O auditor só recebe permissões de consulta <span class="lei">art. 169</span>.</p>
        <form method="POST" action="{{ route('parametros.conceder') }}" class="form-grid">@csrf
            <x-campo nome="user_id" rotulo="Servidor" obrigatorio><select id="campo-user_id" name="user_id" class="input" required><option value="">Selecione</option>@foreach ($usuarios as $u)<option value="{{ $u->id }}" @selected((int) old('user_id') === $u->id)>{{ $u->name }} · {{ $rotulosPerfis[$u->perfilPrincipal()] ?? 'sem perfil' }}</option>@endforeach</select></x-campo>
            <x-campo nome="permissao" rotulo="Permissão" obrigatorio><select id="campo-permissao" name="permissao" class="input" required><option value="">Selecione</option>@foreach ($concediveis as $k => $r)<option value="{{ $k }}" @selected(old('permissao') === $k)>{{ $r }}</option>@endforeach</select></x-campo>
            <x-campo nome="justificativa" rotulo="Justificativa" obrigatorio classe="full" maxlength="1000" ajuda="Mínimo 20 caracteres. Fica na trilha de auditoria." />
            <div class="btns full"><button class="btn btn-p" type="submit">Conceder</button></div>
        </form>
    </section>

    <section class="card card-flush">
        <h2 style="padding:16px 16px 0">Permissões extras vigentes</h2>
        <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Servidor</th><th>Permissão</th><th>Justificativa</th><th>Concedida por</th><th class="acoes"><span class="sr">Ações</span></th></tr></thead><tbody>
            @forelse ($concessoes as $c)
                <tr>
                    <td>{{ $c->usuario?->name }}</td>
                    <td>{{ $permissoes[$c->permissao] ?? $c->permissao }}</td>
                    <td class="small">{{ $c->justificativa }}</td>
                    <td class="small">{{ $c->concedente?->name }}<div class="muted">{{ $c->created_at?->format('d/m/Y') }}</div></td>
                    <td class="acoes"><form method="POST" action="{{ route('parametros.revogar', $c) }}" style="display:inline-flex;gap:6px;align-items:center">@csrf
                        <label class="sr" for="motivo-{{ $c->id }}">Motivo da revogação</label><input id="motivo-{{ $c->id }}" name="motivo" class="input" style="width:160px" placeholder="Motivo" minlength="10" required>
                        <button class="btn btn-s btn-sm" type="submit">Revogar</button></form></td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Nenhuma permissão extra vigente. Cada servidor tem só as permissões do seu perfil.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>

    <details class="card">
        <summary><b>Permissões de cada perfil</b></summary>
        <div class="tbl-wrap"><table class="tbl small"><thead><tr><th>Permissão</th>@foreach ($perfis as $perfil => $lista)@continue($perfil === 'administrador-geral')<th>{{ $rotulosPerfis[$perfil] ?? $perfil }}</th>@endforeach</tr></thead><tbody>
            @foreach ($permissoes as $k => $r)
                <tr><td>{{ $r }}</td>@foreach ($perfis as $perfil => $lista)@continue($perfil === 'administrador-geral')<td>@if (in_array($k, $lista, true))<span aria-label="sim">✓</span>@endif</td>@endforeach</tr>
            @endforeach
        </tbody></table></div>
    </details>
</x-layouts.app>
