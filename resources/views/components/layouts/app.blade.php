<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Inbox' }} · Triage Desk</title>
    @if (! empty($refresh))
        {{-- Re-check while waiting for the queue worker. --}}
        <meta http-equiv="refresh" content="{{ (int) $refresh }}">
    @endif
    <style>
        :root {
            --bg: #f6f7f9; --surface: #ffffff; --text: #1d2330; --muted: #5d6677; --border: #dfe3ea;
            --accent: #2457c5; --accent-text: #ffffff;
            --red: #b42318; --red-bg: #fdecea; --amber: #915b00; --amber-bg: #fff4dc;
            --green: #1d7a46; --green-bg: #e5f5ec; --blue: #2457c5; --blue-bg: #e8effc;
            --purple: #6b3fb5; --purple-bg: #f1ebfb; --gray: #555e6e; --gray-bg: #eceff3;
            --code-bg: #f0f2f5;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #12151b; --surface: #1b1f27; --text: #e6e9ef; --muted: #9aa3b2; --border: #2e3440;
                --accent: #6d97f0; --accent-text: #0d1117;
                --red: #ff8a80; --red-bg: #3a1d1b; --amber: #f5c26b; --amber-bg: #3a2e14;
                --green: #7fd6a1; --green-bg: #173326; --blue: #8fb1f5; --blue-bg: #1b2a47;
                --purple: #c4a6f5; --purple-bg: #2b2140; --gray: #b3bac6; --gray-bg: #2a2f39;
                --code-bg: #232833;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        a { color: var(--accent); }
        header.top { background: var(--surface); border-bottom: 1px solid var(--border); }
        header.top .wrap { display: flex; align-items: center; gap: 16px; padding-top: 12px; padding-bottom: 12px; }
        header.top .brand { font-weight: 700; color: var(--text); text-decoration: none; font-size: 17px; }
        header.top .tag { color: var(--muted); font-size: 13px; }
        .wrap { max-width: 1120px; margin: 0 auto; padding: 0 16px; }
        main { padding: 24px 0 48px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 16px; margin: 0 0 12px; }
        .muted { color: var(--muted); }
        .small { font-size: 13px; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 18px; margin-bottom: 16px; }
        .flash { padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; }
        .flash.success { background: var(--green-bg); color: var(--green); }
        .flash.error { background: var(--red-bg); color: var(--red); }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
        .badge.red { background: var(--red-bg); color: var(--red); }
        .badge.amber { background: var(--amber-bg); color: var(--amber); }
        .badge.green { background: var(--green-bg); color: var(--green); }
        .badge.blue { background: var(--blue-bg); color: var(--blue); }
        .badge.purple { background: var(--purple-bg); color: var(--purple); }
        .badge.gray { background: var(--gray-bg); color: var(--gray); }
        .tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 16px 0; }
        .tabs a { padding: 6px 12px; border-radius: 8px; text-decoration: none; color: var(--muted); border: 1px solid transparent; }
        .tabs a.active { background: var(--surface); color: var(--text); border-color: var(--border); font-weight: 600; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border); vertical-align: top; }
        th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
        tr:last-child td { border-bottom: 0; }
        td.preview a { color: var(--text); text-decoration: none; }
        td.preview a:hover { text-decoration: underline; }
        .grid { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap: 16px; align-items: start; }
        @media (max-width: 860px) { .grid { grid-template-columns: 1fr; } .hide-sm { display: none; } }
        .message-text { white-space: pre-wrap; background: var(--code-bg); padding: 12px 14px; border-radius: 8px; margin: 0; }
        dl.facts { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: 0; }
        dl.facts dt { color: var(--muted); }
        dl.facts dd { margin: 0; }
        pre.json { background: var(--code-bg); padding: 10px 12px; border-radius: 8px; overflow-x: auto; font-size: 12.5px; margin: 8px 0 0; }
        label { display: block; font-weight: 600; margin: 10px 0 4px; font-size: 14px; }
        input[type=text], textarea { width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text); font: inherit; }
        textarea { min-height: 110px; resize: vertical; }
        .btn { display: inline-block; padding: 8px 14px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface); color: var(--text); font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
        .btn.primary { background: var(--accent); border-color: var(--accent); color: var(--accent-text); }
        .btn.danger { color: var(--red); }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        details { border-top: 1px solid var(--border); padding-top: 10px; margin-top: 12px; }
        details summary { cursor: pointer; font-weight: 600; }
        .error-text { color: var(--red); font-size: 13px; margin-top: 4px; }
        ol.timeline { list-style: none; padding: 0; margin: 0; }
        ol.timeline li { padding: 8px 0; border-bottom: 1px solid var(--border); }
        ol.timeline li:last-child { border-bottom: 0; }
        .banner { padding: 12px 14px; border-radius: 8px; margin-bottom: 12px; }
        .banner.red { background: var(--red-bg); color: var(--red); }
        .banner.purple { background: var(--purple-bg); color: var(--purple); }
    </style>
</head>
<body>
    <header class="top">
        <div class="wrap">
            <a class="brand" href="{{ route('inbox') }}">Triage Desk</a>
            <span class="tag">Telehealth message triage demo · synthetic data only · AI suggests, humans decide</span>
        </div>
    </header>
    <main class="wrap">
        @if (session('success'))
            <div class="flash success" role="status">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="flash error" role="alert">{{ session('error') }}</div>
        @endif

        {{ $slot }}
    </main>
</body>
</html>
