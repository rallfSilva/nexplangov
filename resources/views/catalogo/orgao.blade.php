<x-layouts.app titulo="Catálogo do órgão">
    <div class="row">
        <div>
            <h1 class="h1">Catálogo do órgão</h1>
            <p class="sub">Descrição padronizada, atributos e imagem de cada item, ligada ao código CATMAT/CATSER <span class="lei">art. 19, II, e art. 40, § 1º, I</span></p>
        </div>
        @can('catalogo.gerenciar')<a class="btn btn-p" href="{{ route('catalogo.orgao.create') }}"><x-icone nome="mais" tamanho="16" />Novo item</a>@endcan
    </div>
    <form method="GET" class="card filtros" style="align-items:flex-end" role="search">
        <div class="campo"><label for="q">Descrição ou código</label><input id="q" name="q" class="input" value="{{ $q }}" maxlength="100" placeholder="Ex.: papel A4"></div>
        <div class="campo"><label for="situacao">Situação</label><select id="situacao" name="situacao" class="input"><option value="ativos" @selected($situacao === 'ativos')>Ativos</option><option value="inativos" @selected($situacao === 'inativos')>Inativos</option><option value="todos" @selected($situacao === 'todos')>Todos</option></select></div>
        <div class="btns"><button class="btn btn-p" type="submit">Buscar</button><a class="btn btn-g" href="{{ route('catalogo.index') }}">Catálogo nacional</a></div>
    </form>
    <section class="card card-flush"><div class="tbl-wrap"><table class="tbl"><thead><tr><th style="width:64px"><span class="sr">Imagem</span></th><th>Item</th><th>Código</th><th>Unidade</th><th>Atributos</th><th class="acoes"><span class="sr">Ações</span></th></tr></thead><tbody>
        @forelse ($itens as $i)
            <tr>
                <td>@if ($i->imagem_caminho)<img src="{{ route('catalogo.orgao.imagem', $i) }}" alt="Imagem de {{ $i->descricao }}" width="48" height="48" style="object-fit:cover;border-radius:8px">@endif</td>
                <td><b>{{ $i->descricao }}</b><div class="small muted">{{ \App\Models\ItemCatalogoOrgao::TIPOS[$i->tipo] }}@if ($i->natureza_despesa) · natureza {{ $i->natureza_despesa }}@endif</div>@unless ($i->ativo)<div><span class="b b-mute">inativo</span> <span class="small muted">{{ $i->motivo_inativacao }}</span></div>@endunless</td>
                <td class="valor">{{ $i->codigo_catalogo ?? '—' }}</td>
                <td>{{ $i->unidade_fornecimento }}</td>
                <td class="small">@foreach ($i->atributos ?? [] as $a)<div>{{ $a['nome'] }}: <b>{{ $a['valor'] }}</b></div>@endforeach</td>
                <td class="acoes">@can('catalogo.gerenciar')
                    <a class="btn btn-g btn-sm" href="{{ route('catalogo.orgao.edit', $i) }}">Editar</a>
                    <form method="POST" action="{{ route('catalogo.orgao.ativo', $i) }}" style="display:inline-flex;gap:6px;align-items:center">@csrf @method('PATCH')
                        @if ($i->ativo)<label class="sr" for="motivo-{{ $i->id }}">Motivo da inativação</label><input id="motivo-{{ $i->id }}" name="motivo" class="input" style="width:160px" placeholder="Motivo" minlength="10" required>@endif
                        <button class="btn btn-s btn-sm" type="submit">{{ $i->ativo ? 'Inativar' : 'Reativar' }}</button>
                    </form>
                @endcan</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">Nenhum item. @can('catalogo.gerenciar')Cadastre os itens que o órgão compra com frequência.@endcan</td></tr>
        @endforelse
    </tbody></table></div></section>
    <div class="paginacao">{{ $itens->links() }}</div>
</x-layouts.app>
