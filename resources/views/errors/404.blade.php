<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Not found') }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 1.5rem; background: #f8fafc; color: #111827;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .card {
            background: #fff; max-width: 30rem; width: 100%; border-radius: 28px; padding: 2.5rem 2.25rem;
            box-shadow: 0 2px 14px rgba(15,23,42,.06); text-align: center;
        }
        .icon { font-size: 2.5rem; line-height: 1; }
        h1 { font-size: 1.4rem; margin: 1rem 0 .5rem; }
        p { color: #4b5563; line-height: 1.6; margin: .5rem 0; }
        a.btn {
            display: inline-block; margin-top: 1.4rem; padding: 0 1.4rem; height: 2.5rem; line-height: 2.5rem; border-radius: 9999px;
            background: #3b82d6; color: #fff; text-decoration: none; font-weight: 400;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">🔍</div>
        <h1>404 — {{ __('Not found') }}</h1>
        <p>{{ __('The page or data you are looking for could not be found.') }}</p>
        <a class="btn" href="{{ url('/') }}">{{ __('Back to home') }}</a>
    </div>
</body>
</html>
