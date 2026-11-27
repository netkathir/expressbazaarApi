@extends('emails.layouts.branded')

@section('content')
    {!! nl2br(e($messageBody)) !!}
@endsection
