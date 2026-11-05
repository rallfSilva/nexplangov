<x-layouts.app titulo="Fluxos de documentos">
    <div class="row">
        <div><h1 class="h1">Fluxos de documentos</h1><p class="sub">Defina a ordem das peças, os prazos, quem revisa, aprova e assina. O fluxo padrão é aplicado quando um processo é aberto e fica congelado nele.</p></div>
        <a href="{{ route('fluxos.create') }}" class="btn btn-p"><x-icone nome="mais" />Novo fluxo</a>
    </div>
    <div class="card card-flush"><div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Fluxo</th><th>Vale para</th><th>Etapas</th><th>Situação</th><th class="acoes"></th></tr></thead>
        <tbody>
            @forelse ($fluxos as $f)
                <tr>
                    <td><b>{{ $f->nome }}</b>@if ($f->descricao)<div class="muted small">{{ $f->descricao }}</div>@endif</td>
                    <td>{{ $f->abrangencia() }}@if ($f->roteiro_unidades)<div class="muted small">Roteiro: {{ collect($f->roteiro_unidades)->map(fn ($id) => $siglas[$id] ?? '?')->implode(' → ') }}</div>@endif</td>
                    <td class="valor">{{ $f->etapas_count }}</td>
                    <td>@if ($f->padrao)<span class="b b-ok">Padrão</span> @endif @if (! $f->ativo)<span class="b b-mute">Inativo</span>@endif</td>
                    <td class="acoes"><a class="btn btn-s btn-sm" href="{{ route('fluxos.edit', $f) }}"><x-icone nome="editar" tamanho="16" />Abrir</a></td>
                </tr>
            @empty
                <tr><td colspan="5" class="vazio"><b>Nenhum fluxo</b><br>Sem fluxo, as peças são concluídas direto, sem revisão, aprovação ou assinatura.</td></tr>
            @endforelse
        </tbody>
    </table></div></div>
</x-layouts.app>
