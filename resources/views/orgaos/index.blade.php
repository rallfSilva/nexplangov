<x-layouts.app titulo="Órgãos">
    <div class="row">
        <div>
            <h1 class="h1">Órgãos</h1>
            <p class="sub">Cada órgão enxerga somente os próprios dados. Só o administrador geral vê esta lista.</p>
        </div>
        <a href="{{ route('orgaos.create') }}" class="btn btn-p"><x-icone nome="mais" />Novo órgão</a>
    </div>

    <div class="card card-flush">
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Sigla</th><th>Nome</th><th>CNPJ</th><th class="num">Unidades</th><th class="num">Usuários</th><th>Situação</th><th class="acoes"><span class="sr-only">Ações</span></th></tr></thead>
                <tbody>
                    @forelse ($orgaos as $orgao)
                        <tr>
                            <td class="sigla">{{ $orgao->sigla }}</td>
                            <td>{{ $orgao->nome }}<div class="muted small">{{ $orgao->municipio }}/{{ $orgao->uf }} · {{ ucfirst($orgao->esfera) }}</div></td>
                            <td class="muted">{{ $orgao->cnpj ?? '—' }}</td>
                            <td class="num">{{ $orgao->unidades_count }}</td>
                            <td class="num">{{ $orgao->usuarios_count }}</td>
                            <td>@if ($orgao->ativo)<span class="b b-ok">Ativo</span>@else<span class="b b-mute">Inativo</span>@endif</td>
                            <td class="acoes"><a href="{{ route('orgaos.edit', $orgao) }}" class="btn btn-s btn-sm"><x-icone nome="editar" tamanho="16" />Editar</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="vazio"><b>Nenhum órgão cadastrado</b>Cadastre o primeiro órgão para começar.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
