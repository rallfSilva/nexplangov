<x-layouts.app titulo="Intenção de registro de preços">
    <div><h1 class="h1">Intenção de registro de preços (IRP)</h1><p class="sub">O órgão gerenciador colhe as necessidades dos participantes antes de licitar para registro de preços. <span class="lei">Art. 86</span></p></div>

    @can('irp.gerenciar')
        <section class="card"><h2>Abrir uma IRP</h2>
            <p class="hint">Prazo mínimo de manifestação: {{ $prazoMinimo }} dias úteis (regulamento do ente; ajustável pelo administrador do sistema).</p>
            <form method="POST" action="{{ route('irp.store') }}" class="form-grid">@csrf
                <div class="campo full"><label for="objeto">Objeto</label><input id="objeto" name="objeto" class="input" value="{{ old('objeto') }}" required maxlength="500"></div>
                <div class="campo"><label for="manifestacao_ate">Manifestações até</label><input id="manifestacao_ate" type="date" name="manifestacao_ate" class="input" value="{{ old('manifestacao_ate') }}" required></div>
                <div class="campo"><label for="unidade_id">Unidade gerenciadora</label><select id="unidade_id" name="unidade_id" class="input"><option value="">—</option>@foreach ($unidades as $u)<option value="{{ $u->id }}">{{ $u->sigla }} - {{ $u->nome }}</option>@endforeach</select></div>
                <div class="campo full"><label for="justificativa">Justificativa</label><textarea id="justificativa" name="justificativa" class="input" rows="3">{{ old('justificativa') }}</textarea></div>
                <div class="form-acoes full"><button class="btn btn-p" type="submit"><x-icone nome="mais" />Abrir IRP</button></div></form>
        </section>
    @endcan

    <div class="card card-flush"><div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>IRP</th><th>Objeto</th><th>Manifestações até</th><th>Situação</th><th class="num">Manifestações</th><th></th></tr></thead>
        <tbody>
            @forelse ($irps as $i)
                <tr><td><b>{{ $i->identificacao() }}</b></td><td>{{ \Illuminate\Support\Str::limit($i->objeto, 80) }}</td><td>{{ $i->manifestacao_ate->format('d/m/Y') }}</td>
                    <td><span class="b {{ $i->situacao === 'aberta' ? 'b-warn' : ($i->situacao === 'consolidada' ? 'b-ok' : 'b-mute') }}">{{ \App\Models\Irp::SITUACOES[$i->situacao] }}</span></td>
                    <td class="num">{{ $i->manifestacoes_count }}</td><td class="acoes"><a class="btn btn-s btn-sm" href="{{ route('irp.show', $i) }}">Abrir</a></td></tr>
            @empty
                <tr><td colspan="6" class="vazio"><b>Nenhuma IRP</b></td></tr>
            @endforelse
        </tbody>
    </table></div></div>
</x-layouts.app>
