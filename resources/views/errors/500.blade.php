<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Something went wrong') }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 1.5rem; background: #f3f4f6; color: #111827;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .card {
            background: #fff; max-width: 30rem; width: 100%; border-radius: 14px; padding: 2.25rem;
            box-shadow: 0 10px 30px -12px rgba(0,0,0,.25); text-align: center;
        }
        .icon { font-size: 2.5rem; line-height: 1; }
        h1 { font-size: 1.4rem; margin: 1rem 0 .5rem; }
        p { color: #4b5563; line-height: 1.6; margin: .5rem 0; }
        a.btn {
            display: inline-block; margin-top: 1.4rem; padding: .6rem 1.2rem; border-radius: 8px;
            background: #2563eb; color: #fff; text-decoration: none; font-weight: 600;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #0f1319; color: #e7ebf1; }
            .card { background: #161b23; box-shadow: 0 12px 32px -18px rgba(0,0,0,.7); }
            p { color: #a9b2bf; }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">⚠️</div>
        <h1>500 — {{ __('Something went wrong') }}</h1>
        <p>{{ __('An unexpected error occurred on our end. Please try again in a moment.') }}</p>
        <a class="btn" href="{{ url('/') }}">{{ __('Back to home') }}</a>
    </div>
</body>
</html>
