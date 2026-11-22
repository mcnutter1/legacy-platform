@extends('layouts.public')
@section('title', 'Development sign-in')
@section('content')
    <h1 class="font-serif text-2xl">Development sign-in</h1>
    <p class="mt-2 text-sm text-muted">Local only. Refused when APP_ENV=production.</p>
    <form method="post" action="/auth/dev-login" class="mt-6 space-y-4">
        @csrf
        <label class="field"><span>User id</span><input name="uid" value="dev-admin" required pattern="[A-Za-z0-9_\-]{3,40}"></label>
        <label class="field"><span>Name</span><input name="name" value="Dev Administrator" required></label>
        <label class="field"><span>Email</span><input name="email" type="email" value="admin@example.test" required></label>
        <button class="btn-primary w-full" type="submit">Sign in</button>
    </form>
@endsection
