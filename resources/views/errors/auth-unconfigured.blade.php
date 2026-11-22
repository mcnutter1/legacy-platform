@extends('layouts.public')
@section('title', 'Not configured')
@section('content')
    <h1 class="font-serif text-2xl">Sign-in is not configured</h1>
    <p class="mt-2 text-sm text-muted">This deployment is waiting for its McNutt Cloud application registration (AUTH_APP_ID and AUTH_APP_SECRET).</p>
@endsection
