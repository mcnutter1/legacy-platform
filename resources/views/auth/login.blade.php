@extends('layouts.public')
@section('title', 'Sign in · McNutt Legacy')
@section('content')
    <h1 class="font-serif text-2xl">Welcome back</h1>
    <p class="mt-2 text-sm text-muted">Sign in with your McNutt Cloud account. You will return here after verification.</p>
    <a href="{{ $loginUrl }}" class="btn-primary mt-6 w-full">Continue to McNutt Cloud</a>
@endsection
