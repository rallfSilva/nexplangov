@extends('errors.layout')
@section('codigo', '422')
@section('titulo', 'Operação não permitida')
@section('mensagem', ($exception ?? null)?->getMessage() ?: 'Não foi possível concluir a operação. Confira os dados e tente de novo.')
