<x-layouts.app titulo="Assinaturas pendentes">
    <div class="row">
        <div>
            <h1 class="h1">Assinaturas pendentes</h1>
            <p class="sub">Peças que esperam a sua assinatura. Marque as que quer assinar e confirme a senha uma vez.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('assinaturas.lote') }}" class="card card-flush">@csrf
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th style="width:44px"><span class="sr-only">Selecionar</span></th><th>Processo</th><th>Peça</th><th>Versão</th><th>Desde</th><th></th></tr></thead>
                <tbody>
                    @forelse ($pendentes as $d)
                        <tr>
                            <td><input type="checkbox" name="documentos[]" value="{{ $d->id }}" id="doc-{{ $d->id }}" checked aria-label="Assinar {{ $tipos[$d->tipo]['rotulo'] ?? $d->tipo }} do processo {{ $d->processo->identificacao() }}"></td>
                            <td><a class="sigla" href="{{ route('processos.show', $d->processo) }}">{{ $d->processo->identificacao() }}</a><div class="muted small">{{ \Illuminate\Support\Str::limit($d->processo->objeto, 70) }}</div></td>
                            <td><label for="doc-{{ $d->id }}">{{ $tipos[$d->tipo]['rotulo'] ?? $d->tipo }}</label></td>
                            <td>{{ $d->versao }}</td>
                            <td>{{ $d->updated_at->format('d/m/Y') }}</td>
                            <td><a class="btn btn-s btn-sm" href="{{ route('documentos.edit', [$d->processo, $d->tipo]) }}">Ler</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="muted">Nenhuma peça aguardando a sua assinatura.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($pendentes->isNotEmpty())
            <div class="btns" style="padding:16px 20px;align-items:flex-end">
                <div class="campo" style="margin:0"><label for="senha-lote">Sua senha</label><input id="senha-lote" type="password" name="senha" class="input" required autocomplete="current-password" style="width:240px"></div>
                <button type="submit" class="btn btn-p">Assinar as marcadas</button>
                <span class="small muted">Cada peça passa pelas mesmas regras da assinatura individual e recebe o próprio código de validação.</span>
            </div>
        @endif
    </form>
</x-layouts.app>
