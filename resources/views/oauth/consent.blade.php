@extends('layouts.public')
@section('title', 'Connect an app')
@section('content')
    <h1 class="font-serif text-2xl">Connect “{{ $client->name }}”</h1>
    <p class="mt-2 text-sm text-muted">This app is asking to use McNutt Legacy on your behalf. It will only see what your own access allows, and you can disconnect it any time in Settings.</p>
    <form method="post" action="/oauth/authorize" class="mt-6 space-y-5">
        @csrf
        @foreach($params as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
        <label class="field"><span>Workspace</span>
            <select name="membership_id" required>
                @foreach($memberships as $m)<option value="{{ $m->id }}">{{ $m->workspace->name }}</option>@endforeach
            </select>
        </label>
        <fieldset class="space-y-2">
            <legend class="text-sm font-medium">Permissions</legend>
            @foreach($scopes as $key => $s)
                <label class="flex items-start gap-3 rounded-lg border border-line p-3 text-sm">
                    <input type="checkbox" name="scopes[]" value="{{ $key }}" checked class="mt-1">
                    <span><span class="font-medium">{{ $s['label'] }}</span><br><span class="text-muted">{{ $key }}{{ in_array('write', $s['actions']) ? ' · creates drafts or proposals for your approval' : ' · read-only' }}</span></span>
                </label>
            @endforeach
        </fieldset>
        @unless($stepupFresh)
            @if($stepupEnrolled)
                <label class="field"><span>Authenticator code</span><input name="totp" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" required></label>
            @else
                <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Set up an authenticator in McNutt Legacy (Settings → Security) before connecting apps.</p>
            @endif
        @endunless
        @if($errors->any())<p class="text-sm text-red-700" role="alert">{{ $errors->first() }}</p>@endif
        <div class="flex gap-3">
            <button class="btn-secondary flex-1" type="submit" name="decision" value="deny">Cancel</button>
            <button class="btn-primary flex-1" type="submit" name="decision" value="allow" @if(!$stepupFresh && !$stepupEnrolled) disabled @endif>Allow</button>
        </div>
    </form>
@endsection
