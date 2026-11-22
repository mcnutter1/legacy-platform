@extends('layouts.public')
@section('title', 'Upload paystub')
@section('content')
    <h1 class="font-serif text-2xl">Upload a paystub</h1>
    <p class="mt-2 text-sm text-muted">For {{ $employer }}. Take a photo or choose a PDF. Nothing changes until you confirm the extracted values in McNutt Legacy.</p>
    <form method="post" action="/u/{{ $token }}" enctype="multipart/form-data" class="mt-6 space-y-4">
        @csrf
        <label class="field"><span>Paystub</span><input type="file" name="file" accept="application/pdf,image/*" capture="environment" required></label>
        @error('file')<p class="text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
        <button class="btn-primary w-full" type="submit">Upload securely</button>
    </form>
@endsection
