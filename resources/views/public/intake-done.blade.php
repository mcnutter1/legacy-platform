@extends('layouts.public')
@section('title', 'Paystub received')
@section('content')
    <h1 class="font-serif text-2xl">Received</h1>
    <p class="mt-2 text-sm text-muted">The file is being scanned and read. Review and confirm the values in your inbox; the tax forecast updates after you approve.</p>
    <a href="{{ $reviewUrl }}" class="btn-primary mt-6 w-full">Open review inbox</a>
@endsection
