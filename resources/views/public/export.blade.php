@extends('layouts.public')
@section('title', 'Shared documents')
@section('content')
    <h1 class="font-serif text-2xl">Documents for {{ $name }}</h1>
    <p class="mt-2 text-sm text-muted">Enter the 6-digit access code you received separately from the sender. This link expires {{ \Illuminate\Support\Carbon::parse($expires)->toFormattedDateString() }}.</p>
    <form method="post" action="/x/{{ $token }}" class="mt-6 space-y-4">
        @csrf
        <label class="field"><span>Access code</span><input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" required></label>
        @error('code')<p class="text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
        <button class="btn-primary w-full" type="submit">Download package</button>
    </form>
@endsection
