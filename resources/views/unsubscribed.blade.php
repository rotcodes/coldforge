<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Unsubscribed</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #0a0a0a; color: #e5e5e5;
               font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        .card { max-width: 420px; margin: 16px; padding: 40px 32px; border-radius: 16px; text-align: center;
                background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08); }
        .dot { width: 48px; height: 48px; margin: 0 auto 20px; border-radius: 999px;
               background: linear-gradient(135deg, #6366f1, #a855f7); display: grid; place-items: center; }
        h1 { margin: 0 0 8px; font-size: 20px; font-weight: 600; }
        p { margin: 0; color: #a3a3a3; font-size: 14px; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="card">
        <div class="dot">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
        </div>
        <h1>You're unsubscribed</h1>
        <p>{{ $email }} won't receive any more emails from us. Sorry for the noise.</p>
    </div>
</body>
</html>
