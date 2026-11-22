@extends('layouts.public')
@section('title', 'Sign-in unavailable')
@section('content')
    <h1 class="font-serif text-2xl">Sign-in service unavailable</h1>
    <p class="mt-2 text-sm text-muted">We could not confirm your session with McNutt Cloud, so access is paused to keep your information safe. Please try again in a few minutes.</p>
    <a href="/" class="btn-secondary mt-6 w-full">Try again</a>
@endsection
