<x-layouts.app titulo="Devolução de dados">
    <div class="row">
        <div>
            <h1 class="h1">Devolução de dados do órgão</h1>
            <p class="sub">Pacote com todos os registros, os arquivos enviados e a trilha de auditoria, para entregar ao órgão no fim do contrato ou quando ele pedir.</p>
        </div>
    </div>

    <div class="aviso-lei" role="note">O pacote traz cada tabela em <b>CSV</b> (para planilha) e <b>JSON</b> (para importar em outro sistema), os arquivos no mesmo caminho em que estavam e um <b>manifesto com o SHA-256</b> de cada parte. Contém dados pessoais legíveis: guarde em local de acesso restrito (LGPD, art. 46). Senhas e segredos de acesso nunca saem. O arquivo fica disponível por {{ \App\Models\PacoteDevolucao::DIAS_DISPONIVEL }} dias.</div>

    <section class="card">
        <h2>Gerar novo pacote</h2>
        <form method="POST" action="{{ route('devolucao.store') }}" class="form-grid" style="margin-top:12px" onsubmit="return confirm('Gerar o pacote com todos os dados do órgão?')">
            @csrf
            @if ($orgaos->isNotEmpty())
                <x-campo nome="orgao_id" rotulo="Órgão" obrigatorio>
                    <select id="campo-orgao_id" name="orgao_id" class="input" required>
                        <option value="">Escolha</option>
                        @foreach ($orgaos as $o)<option value="{{ $o->id }}">{{ $o->sigla }} · {{ $o->nome }}</option>@endforeach
                    </select>
                </x-campo>
            @endif
            <div class="full form-acoes"><button class="btn btn-p" type="submit"><x-icone nome="pasta" />Gerar pacote</button></div>
        </form>
    </section>

    <div class="card card-flush">
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Pedido em</th>@if ($orgaos->isNotEmpty())<th>Órgão</th>@endif<th>Por</th><th>Situação</th><th class="num">Registros</th><th class="num">Arquivos</th><th class="num">Tamanho</th><th>SHA-256</th><th class="acoes"><span class="sr-only">Ações</span></th></tr></thead>
                <tbody>
                    @forelse ($pacotes as $p)
                        @php($s = $p->disponivel() || $p->situacao !== 'pronto' ? \App\Models\PacoteDevolucao::SITUACOES[$p->situacao] : \App\Models\PacoteDevolucao::SITUACOES['expirado'])
                        <tr>
                            <td>{{ $p->created_at->format('d/m/Y H:i') }}</td>
                            @if ($orgaos->isNotEmpty())<td class="sigla">{{ $p->orgao?->sigla }}</td>@endif
                            <td>{{ $p->usuario?->name ?? '—' }}</td>
                            <td><span class="b {{ $s[1] }}">{{ $s[0] }}</span>@if ($p->erro)<div class="small" style="color:#9F1D1D">{{ $p->erro }}</div>@endif @if ($p->disponivel())<div class="muted small">até {{ $p->expira_em->format('d/m/Y') }}</div>@endif</td>
                            <td class="num">{{ $p->resumo ? number_format(array_sum($p->resumo['tabelas'] ?? []), 0, ',', '.') : '—' }}</td>
                            <td class="num">{{ $p->resumo['arquivos'] ?? '—' }}</td>
                            <td class="num">{{ $p->tamanho ? number_format($p->tamanho / 1048576, 1, ',', '.').' MB' : '—' }}</td>
                            <td><code class="small" style="word-break:break-all">{{ $p->sha256 ? substr($p->sha256, 0, 16).'…' : '—' }}</code></td>
                            <td class="acoes">@if ($p->disponivel())<a href="{{ route('devolucao.baixar', $p) }}" class="btn btn-s btn-sm"><x-icone nome="download" tamanho="16" /><span class="sr-only">Baixar pacote de {{ $p->created_at->format('d/m/Y H:i') }}</span></a>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="vazio"><b>Nenhum pacote gerado</b>Gere um pacote quando o órgão pedir a devolução dos dados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
