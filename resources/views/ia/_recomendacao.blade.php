{{-- Recomendações da NexMind IA numa etapa (PCA, preços, contrato). Uso: @include('ia._recomendacao', ['rota' => ..., 'titulo' => ...]) --}}
@if (\App\Licitacao\Ia\AssistenteIa::habilitada())
    @can('ia.usar')
        <section class="card">
            <div class="row" style="align-items:flex-start">
                <div><h2>Recomendações da NexMind IA</h2><p class="hint">{{ $titulo }}. A IA só sugere: quem decide é o servidor.</p></div>
                <form method="POST" action="{{ $rota }}">@csrf<button class="btn btn-s btn-sm" type="submit">Pedir recomendações</button></form>
            </div>
            @if (session('recomendacao_ia'))
                <div class="aviso-lei" style="margin-top:10px;white-space:pre-line">{{ session('recomendacao_ia') }}</div>
                <p class="small muted" style="margin-top:6px">Texto gerado por IA, sem dado pessoal. Confira cada ponto antes de agir.</p>
            @endif
        </section>
    @endcan
@endif
