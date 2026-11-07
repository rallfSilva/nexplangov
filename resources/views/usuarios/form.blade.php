<x-layouts.app :titulo="$usuario->exists ? 'Editar usuário' : 'Novo usuário'">
    <div>
        <a href="{{ route('usuarios.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Usuários</a>
        <h1 class="h1" style="margin-top:8px">{{ $usuario->exists ? $usuario->name : 'Novo usuário' }}</h1>
        @unless ($usuario->exists)
            <p class="sub">O sistema não define senha: a pessoa recebe por e-mail um link para criar a própria senha.</p>
        @endunless
    </div>

    <form method="POST" action="{{ $usuario->exists ? route('usuarios.update', $usuario) : route('usuarios.store') }}" class="card" novalidate>
        @csrf
        @if ($usuario->exists) @method('PUT') @endif
        <div class="form-grid">
            <x-campo nome="name" rotulo="Nome completo" :valor="$usuario->name" obrigatorio autocomplete="off" />
            <x-campo nome="email" rotulo="E-mail institucional" tipo="email" :valor="$usuario->email" obrigatorio autocomplete="off" />
            <x-campo nome="cpf" rotulo="CPF" :valor="$usuario->cpf" inputmode="numeric" placeholder="000.000.000-00" ajuda="Opcional. Na lista aparece sempre com máscara." />
            <x-campo nome="perfil" rotulo="Perfil de acesso" obrigatorio>
                <select id="campo-perfil" name="perfil" class="input" @disabled($usuario->is(auth()->user()))>
                    @foreach ($perfis as $valor => $rotulo)
                        <option value="{{ $valor }}" @selected(old('perfil', $perfilAtual) === $valor)>{{ $rotulo }}</option>
                    @endforeach
                </select>
                @if ($usuario->is(auth()->user()))
                    <input type="hidden" name="perfil" value="{{ $perfilAtual }}">
                    <span class="ajuda">Você não pode alterar o seu próprio perfil.</span>
                @endif
            </x-campo>
            @if ($orgaos->isNotEmpty())
                <x-campo nome="orgao_id" rotulo="Órgão" ajuda="Deixe em branco apenas para administrador geral.">
                    <select id="campo-orgao_id" name="orgao_id" class="input">
                        <option value="">Nenhum (administrador geral)</option>
                        @foreach ($orgaos as $o)
                            <option value="{{ $o->id }}" @selected((int) old('orgao_id', $usuario->orgao_id) === $o->id)>{{ $o->sigla }} · {{ $o->nome }}</option>
                        @endforeach
                    </select>
                </x-campo>
            @endif
            <x-campo nome="unidade_id" rotulo="Unidade de lotação" ajuda="Define em que unidade a pessoa recebe, envia e arquiva processos.">
                <select id="campo-unidade_id" name="unidade_id" class="input">
                    <option value="">Sem lotação</option>
                    @foreach ($unidades as $un)
                        <option value="{{ $un->id }}" @selected((int) old('unidade_id', $usuario->unidade_id) === $un->id)>{{ $un->sigla }} · {{ $un->nome }}@if ($orgaos->isNotEmpty()) ({{ $un->orgao?->sigla }}) @endif</option>
                    @endforeach
                </select>
            </x-campo>
        </div>

        <details style="margin-top:20px">
            <summary style="cursor:pointer;font-weight:600;color:#34729C;font-size:14px">O que cada perfil pode fazer</summary>
            <div class="tbl-wrap" style="margin-top:12px">
                <table class="tbl">
                    <thead><tr><th>Perfil</th><th>Pode</th></tr></thead>
                    <tbody>
                        <tr><td><b>Administrador geral</b></td><td>Tudo, em todos os órgãos. Cadastra órgãos.</td></tr>
                        <tr><td><b>Administrador do órgão</b></td><td>Gerencia unidades, usuários e perfis do próprio órgão e consulta a auditoria.</td></tr>
                        <tr><td><b>Autoridade competente</b></td><td>Aprova o PCA, autoriza a contratação, homologa, revoga ou anula (arts. 71 e 72 da Lei 14.133).</td></tr>
                        <tr><td><b>Agente de contratação ou pregoeiro</b></td><td>Conduz a seleção do fornecedor e publica no PNCP (art. 8º).</td></tr>
                        <tr><td><b>Planejamento e compras</b></td><td>Monta o PCA, instrui o processo (ETP, TR, riscos, pesquisa de preços) e cadastra fornecedores.</td></tr>
                        <tr><td><b>Assessoria jurídica</b></td><td>Emite o parecer jurídico do processo (art. 53).</td></tr>
                        <tr><td><b>Gestor ou fiscal de contrato</b></td><td>Acompanha contratos, registra aditivos, apostilamentos e ocorrências (art. 117).</td></tr>
                        <tr><td><b>Requisitante</b></td><td>Registra demandas (DFD) para o PCA e acompanha as próprias solicitações.</td></tr>
                        <tr><td><b>Controle interno</b></td><td>Somente leitura, incluindo a trilha de auditoria (art. 169).</td></tr>
                    </tbody>
                </table>
            </div>
        </details>

        <div class="form-acoes">
            <a href="{{ route('usuarios.index') }}" class="btn btn-s">Cancelar</a>
            <button type="submit" class="btn btn-p">{{ $usuario->exists ? 'Salvar' : 'Cadastrar e enviar link' }}</button>
        </div>
    </form>
</x-layouts.app>
