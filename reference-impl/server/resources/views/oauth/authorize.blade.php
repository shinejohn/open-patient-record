<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Authorize {{ $client->name }} — OPR Vault</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 30rem; margin: 3rem auto; padding: 0 1rem; color: #1a202c; }
        .card { border: 1px solid #cbd5e0; border-radius: 8px; padding: 1.5rem; }
        h1 { font-size: 1.2rem; }
        ul { padding-left: 1.2rem; }
        label { display: block; margin: .5rem 0; }
        input[type=email], input[type=password] { width: 100%; padding: .5rem; margin-top: .25rem; }
        button { padding: .5rem 1.25rem; border-radius: 6px; border: 1px solid #2b6cb0; background: #2b6cb0; color: #fff; cursor: pointer; }
        button.secondary { background: #fff; color: #2b6cb0; }
        .sensitive { background: #fffaf0; border: 1px solid #ed8936; border-radius: 6px; padding: .75rem; margin: 1rem 0; }
        .error { color: #c53030; }
    </style>
</head>
<body>
<div class="card">
    <h1>“{{ $client->name }}” is asking to view parts of your health record</h1>

    @if (! $authenticated)
        <p>Sign in to continue.</p>
        @if ($errors->any())<p class="error">{{ $errors->first() }}</p>@endif
        <form method="POST" action="{{ url('/oauth/authorize/login') }}">
            <label>Email <input type="email" name="email" required autocomplete="username"></label>
            <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
            <button type="submit">Sign in</button>
        </form>
    @else
        <p>It will be able to <strong>read</strong>:</p>
        <ul>
            @foreach ($scopes['scope'] as $type)
                <li>{{ $type === '*' ? 'Your entire record (excluding specially protected categories below)' : $type }}</li>
            @endforeach
        </ul>

        <form method="POST" action="{{ url('/oauth/authorize/decision') }}">
            <div class="sensitive">
                <strong>Specially protected categories are NOT shared unless you tick them:</strong>
                @foreach ($categories as $category)
                    <label><input type="checkbox" name="sensitive_categories[]" value="{{ $category }}"> {{ str_replace('_', ' ', $category) }}</label>
                @endforeach
            </div>
            <p>You can revoke this app’s access at any time; access ends immediately.</p>
            <button type="submit" name="approve" value="1">Allow</button>
            <button type="submit" name="approve" value="0" class="secondary">Deny</button>
        </form>
    @endif
</div>
</body>
</html>
