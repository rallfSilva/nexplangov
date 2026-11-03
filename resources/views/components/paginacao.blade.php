{{-- Paginação em português, acessível (eMAG 3.1): navegação nomeada, página atual marcada e itens desativados sem link. --}}
@if ($paginator->hasPages())
    <nav class="pag" role="navigation" aria-label="Paginação">
        <p class="pag-info">
            Mostrando {{ $paginator->firstItem() }} a {{ $paginator->lastItem() }} de {{ $paginator->total() }}
        </p>
        <ul class="pag-lista">
            <li>
                @if ($paginator->onFirstPage())
                    <span class="pag-item desativado" aria-disabled="true">‹ Anterior</span>
                @else
                    <a class="pag-item" href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ Anterior</a>
                @endif
            </li>
            @foreach ($elements as $element)
                @if (is_string($element))
                    <li><span class="pag-item reticencias" aria-hidden="true">{{ $element }}</span></li>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <li>
                            @if ($page == $paginator->currentPage())
                                <span class="pag-item atual" aria-current="page"><span class="sr-only">Página </span>{{ $page }}</span>
                            @else
                                <a class="pag-item" href="{{ $url }}"><span class="sr-only">Página </span>{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach
            <li>
                @if ($paginator->hasMorePages())
                    <a class="pag-item" href="{{ $paginator->nextPageUrl() }}" rel="next">Próxima ›</a>
                @else
                    <span class="pag-item desativado" aria-disabled="true">Próxima ›</span>
                @endif
            </li>
        </ul>
    </nav>
@endif
