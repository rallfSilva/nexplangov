<x-layouts.app titulo="Fornecedores">
    <div class="row">
        <div><h1 class="h1">Fornecedores</h1><p class="sub">Cadastro com porte (ME/EPP) e sanções. CPF de pessoa física fica cifrado e aparece mascarado (LGPD).</p></div>
        @can('fornecedores.gerenciar')<a href="{{ route('fornecedores.create') }}" class="btn btn-p"><x-icone nome="mais" />Novo fornecedor</a>@endcan
    </div>
    <form method="GET" class="card filtros" role="search"><div class="campo"><label for="f-busca">Razão social, CNPJ ou CPF</label><input id="f-busca" name="busca" value="{{ request('busca') }}" class="input"></div><div class="btns"><button class="btn btn-s" type="submit"><x-icone nome="busca" />Buscar</button></div></form>
    <div class="card card-flush"><div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Fornecedor</th><th>Documento</th><th>Porte</th><th>Local</th><th>Situação</th><th class="acoes"></th></tr></thead>
        <tbody>
            @forelse ($fornecedores as $f)
                <tr>
                    <td><b>{{ $f->razao_social }}</b>@if ($f->nome_fantasia)<div class="muted small">{{ $f->nome_fantasia }}</div>@endif</td>
                    <td class="valor">{{ $f->documento }}</td>
                    <td>{{ $f->porte ? \App\Models\Fornecedor::PORTES[$f->porte] : '—' }}</td>
                    <td class="muted">{{ $f->municipio }}{{ $f->uf ? '/'.$f->uf : '' }}</td>
                    <td>@if ($f->sancoes_vigentes_count)<span class="b b-err">Impedido</span>@elseif ($f->ativo)<span class="b b-ok">Apto</span>@else<span class="b b-mute">Inativo</span>@endif</td>
                    <td class="acoes">@can('fornecedores.gerenciar')<a class="btn btn-s btn-sm" href="{{ route('fornecedores.edit', $f) }}"><x-icone nome="editar" tamanho="16" />Abrir</a>@endcan</td>
                </tr>
            @empty
                <tr><td colspan="6" class="vazio"><b>Nenhum fornecedor</b></td></tr>
            @endforelse
        </tbody>
    </table></div></div>
    <div class="paginacao">{{ $fornecedores->links() }}</div>
</x-layouts.app>
