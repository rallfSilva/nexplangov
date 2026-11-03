<x-layouts.app titulo="Catálogo CATMAT/CATSER">
    <div>
        <h1 class="h1">Catálogo CATMAT/CATSER</h1>
        <p class="sub">Códigos padronizados para materiais e serviços <span class="lei">art. 19, I; art. 174, § 3º, V</span> · {{ number_format($total['M'], 0, ',', '.') }} materiais e {{ number_format($total['S'], 0, ',', '.') }} serviços carregados</p>
    </div>
    @if ($total['M'] + $total['S'] === 0)
        <div class="aviso-lei" style="border-color:#E0A458;background:#FFF7EC;color:#7A3B0B">O catálogo ainda não foi carregado. O administrador do sistema importa os arquivos do Compras.gov.br com <code>php artisan nexplangov:catalogo-importar arquivo.csv --tipo=M</code>. Enquanto isso o código digitado não é conferido.</div>
    @endif
    <form method="GET" class="card filtros" style="align-items:flex-end" role="search">
        <div class="campo"><label for="q">Código ou palavras da descrição</label><input id="q" name="q" class="input" value="{{ $q }}" maxlength="100" placeholder="Ex.: papel sulfite, 150123"></div>
        <div class="campo"><label for="tipo">Tipo</label><select id="tipo" name="tipo" class="input"><option value="">Todos</option><option value="M" @selected($tipo === 'M')>Material (CATMAT)</option><option value="S" @selected($tipo === 'S')>Serviço (CATSER)</option></select></div>
        <div class="btns"><button class="btn btn-p" type="submit">Buscar</button></div>
    </form>
    @if ($q !== '')
        <section class="card"><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Código</th><th>Tipo</th><th>Descrição</th><th>Classe</th></tr></thead><tbody>
            @forelse ($itens as $i)
                <tr><td class="valor"><b>{{ $i->codigo }}</b>@unless ($i->ativo)<div><span class="b b-mute">inativo</span></div>@endunless</td><td>{{ $i->tipo === 'M' ? 'CATMAT' : 'CATSER' }}</td><td>{{ $i->descricao }}@if ($i->unidade_padrao)<div class="small muted">Unidade: {{ $i->unidade_padrao }}</div>@endif</td><td class="small">{{ $i->classe_nome }}</td></tr>
            @empty
                <tr><td colspan="4" class="muted">Nada encontrado.</td></tr>
            @endforelse
        </tbody></table></div></section>
    @endif
</x-layouts.app>
