@extends('layouts.public')
@section('title', 'Invitation accepted')
@section('content')
    <h1 class="font-serif text-2xl">Invitation accepted</h1>
    @if($pending)
        <p class="mt-2 text-sm text-muted">An administrator will confirm your access shortly. You will see the workspace as soon as it is approved.</p>
    @else
        <p class="mt-2 text-sm text-muted">You now have access to the workspace.</p>
    @endif
    <a href="/" class="btn-primary mt-6 w-full">Open McNutt Legacy</a>
@endsection
