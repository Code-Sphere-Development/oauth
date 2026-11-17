{{--
    Shown by GET /login when the previous sign-in attempt failed.

    Self-contained on purpose: the consuming app's CSS build does not scan
    vendor/, so utility classes would not exist. Publish with
    `php artisan vendor:publish --tag=codesphere-views` to use the app's own
    layout instead.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Sign-in failed') }} – {{ config('app.name') }}</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f3f4f6; --card: #ffffff; --text: #111827; --muted: #4b5563;
            --error: #dc2626; --border: #d1d5db; --primary: #2563eb; --primary-hover: #1d4ed8;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #111827; --card: #1f2937; --text: #f9fafb; --muted: #9ca3af;
                --error: #f87171; --border: #4b5563; --primary: #3b82f6; --primary-hover: #2563eb;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 1.5rem; background: var(--bg); color: var(--text);
            font: 15px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        main {
            width: 100%; max-width: 28rem; padding: 1.75rem; border-radius: 0.75rem;
            background: var(--card); box-shadow: 0 1px 3px rgb(0 0 0 / 0.1), 0 1px 2px rgb(0 0 0 / 0.06);
        }
        .app { margin: 0; font-size: 0.8rem; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: var(--muted); }
        h1 { margin: 0.25rem 0 0; font-size: 1.25rem; }
        .error { margin: 0.75rem 0 0; color: var(--error); }
        .actions { display: flex; flex-direction: column; gap: 0.75rem; margin-top: 1.5rem; }
        .button {
            display: block; padding: 0.625rem 1rem; border-radius: 0.5rem; border: 1px solid var(--border);
            text-align: center; font-weight: 600; text-decoration: none; color: var(--text);
        }
        .button:hover { border-color: var(--muted); }
        .button.primary { border-color: transparent; background: var(--primary); color: #ffffff; }
        .button.primary:hover { background: var(--primary-hover); }
        .button:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
    </style>
</head>
<body>
    <main>
        <p class="app">{{ config('app.name') }}</p>
        <h1>{{ __('Sign-in failed') }}</h1>
        <p class="error" role="alert">{{ $error }}</p>

        <div class="actions">
            @if ($switchAccountUrl)
                <a href="{{ $switchAccountUrl }}" class="button primary">{{ __('Sign in with a different account') }}</a>
            @endif
            <a href="{{ $retryUrl }}" class="button">{{ __('Try again') }}</a>
        </div>
    </main>
</body>
</html>
