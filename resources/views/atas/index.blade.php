<x-layouts.app titulo="Atas de registro de preços">
    <div><h1 class="h1">Atas de registro de preços</h1><p class="sub">Uma ata por fornecedor vencedor, com saldo por item, adesão de órgãos não participantes e prorrogação. <span class="lei">Arts. 82 a 86</span></p></div>

    @can('contratos.gerenciar')
        @if ($processos->isNotEmpty())
            <section class="card"><h2>Gerar atas de um processo homologado</h2>
                <p class="hint">A ata vale por 1 ano e pode ser prorrogada uma vez, por igual período, se o preço continuar vantajoso (art. 84).</p>
                <form method="POST" action="{{ route('atas.gerar') }}" class="form-grid">@csrf
                    <div class="campo full"><label for="processo_id">Processo de registro de preços</label><select id="processo_id" name="processo_id" class="input" required>@foreach ($processos as $p)<option value="{{ $p->id }}">{{ $p->identificacao() }} · {{ \Illuminate\Support\Str::limit($p->objeto, 70) }}</option>@endforeach</select></div>
                    <x-campo nome="assinatura" rotulo="Assinatura" tipo="date" obrigatorio /><x-campo nome="vigencia_inicio" rotulo="Início da vigência" tipo="date" obrigatorio /><x-campo nome="vigencia_fim" rotulo="Fim da vigência" tipo="date" obrigatorio />
                    <div class="form-acoes full"><button class="btn btn-p" type="submit">Gerar atas</button></div></form>
            </section>
        @endif
    @endcan

    <div class="card card-flush"><div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Ata</th><th>Fornecedor</th><th>Processo</th><th>Vigência</th><th>Itens</th><th>Situação</th><th></th></tr></thead>
        <tbody>
            @forelse ($atas as $a)
                <tr><td><b>{{ $a->identificacao() }}</b></td><td>{{ $a->fornecedor->razao_social }}</td><td>{{ $a->processo->identificacao() }}</td>
                    <td>{{ $a->vigencia_inicio->format('d/m/Y') }} a {{ $a->vigencia_fim->format('d/m/Y') }}</td><td class="num">{{ $a->itens_count }}</td>
                    <td><span class="b {{ $a->vigente() ? 'b-ok' : 'b-mute' }}">{{ $a->situacaoExibida() }}</span></td><td class="acoes"><a class="btn btn-s btn-sm" href="{{ route('atas.show', $a) }}">Abrir</a></td></tr>
            @empty
                <tr><td colspan="7" class="vazio"><b>Nenhuma ata</b></td></tr>
            @endforelse
        </tbody>
    </table></div></div>
</x-layouts.app>
