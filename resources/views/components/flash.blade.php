@if (session('sucesso'))
    <div class="flash flash-ok" role="status"><x-icone nome="ok" style="flex:none" /><div>{{ session('sucesso') }}</div></div>
@endif
@if (session('erro'))
    <div class="flash flash-erro" role="alert"><x-icone nome="alerta" style="flex:none" /><div>{{ session('erro') }}@if (session('motivos'))<ul style="margin:8px 0 0;padding-left:18px">@foreach (session('motivos') as $m)<li>{{ $m }}</li>@endforeach</ul>@endif</div></div>
@endif
@if (session('aviso'))
    <div class="flash flash-aviso" role="status"><x-icone nome="alerta" style="flex:none" /><div>{{ session('aviso') }}</div></div>
@endif
@if (session('status'))
    <div class="flash flash-ok" role="status"><x-icone nome="ok" style="flex:none" /><div>{{ __(session('status')) }}</div></div>
@endif
