<x-layouts.app titulo="Preços no Compras.gov.br">
    <div>
        <a href="{{ route('processos.show', [$processo, 'aba' => 'itens']) }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Processo {{ $processo->identificacao() }}</a>
        <h1 class="h1" style="margin-top:8px">Preços no Compras.gov.br</h1>
        <p class="sub">Item {{ $item->numero }}: {{ $item->descricao }} · {{ $item->material_servico === 'M' ? 'CATMAT' : 'CATSER' }} {{ $item->codigo_catalogo }} · compras do último ano <span class="lei">art. 23, § 1º, II</span></p>
    </div>

    @if ($falha)
        <div class="aviso-lei" style="border-color:#E0A458;background:#FFF7EC;color:#7A3B0B">{{ $falha }} Você pode registrar as cotações à mão na tela do processo.</div>
    @elseif (empty($precos))
        <div class="aviso-lei">Nenhuma compra com este código no último ano. Use outras fontes da pesquisa de preços.</div>
    @else
        <form method="POST" action="{{ route('processos.comprasgov.importar', [$processo, $item]) }}" class="card">@csrf
            <p class="hint">Marque as compras comparáveis (mesma unidade e quantidade parecida). Elas entram como contratações similares e o preço de referência é recalculado.</p>
            <div class="tbl-wrap"><table class="tbl"><thead><tr><th></th><th>Data</th><th>Órgão</th><th>Fornecedor</th><th class="num">Qtd</th><th class="num">Valor unitário</th></tr></thead><tbody>
                @foreach ($precos as $p)
                    @php($chave = $p['id'].'|'.$p['data'].'|'.$p['valor'])
                    <tr><td><input type="checkbox" name="selecionados[]" value="{{ $chave }}" aria-label="Importar compra {{ $p['id'] }}"></td>
                        <td>{{ \Illuminate\Support\Carbon::parse($p['data'])->format('d/m/Y') }}</td>
                        <td>{{ $p['orgao'] }}@if ($p['uasg'])<div class="small muted">UASG {{ $p['uasg'] }} · compra {{ $p['id'] }}</div>@endif</td>
                        <td class="small">{{ $p['fornecedor'] }}@if ($p['cnpj'])<div class="muted">{{ $p['cnpj'] }}</div>@endif</td>
                        <td class="num">{{ $p['quantidade'] !== null ? numero_br($p['quantidade'], 2) : '' }} {{ $p['unidade'] }}</td>
                        <td class="num valor"><b>{{ reais($p['valor'], 4) }}</b></td></tr>
                @endforeach
            </tbody></table></div>
            <div class="form-acoes"><button class="btn btn-p" type="submit">Importar selecionados</button></div>
        </form>
    @endif
</x-layouts.app>
