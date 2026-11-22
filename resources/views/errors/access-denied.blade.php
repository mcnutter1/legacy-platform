@extends('layouts.public')
@section('title', 'Access denied')
@section('content')
    <h1 class="font-serif text-2xl">We couldn't continue</h1>
    <p class="mt-2 text-sm text-muted">{{ $reason ?? 'Access was denied.' }}</p>
    <a href="/" class="btn-secondary mt-6 w-full">Return home</a>
@endsection
