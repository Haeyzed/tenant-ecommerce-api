<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #222; }
    h1 { font-size: 14px; margin: 0 0 4px; }
    .meta { color: #666; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #eee; text-align: left; font-weight: bold; }
    th, td { border: 1px solid #ccc; padding: 3px 4px; vertical-align: top; }
    tr:nth-child(even) td { background: #fafafa; }
    .note { margin-top: 8px; color: #a33; }
</style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">
        Generated {{ $generatedAt->toDayDateTimeString() }} UTC
        @foreach ($parameters as $key => $value)
            @if (! is_array($value) && $value !== null && $value !== '')
                &middot; {{ str_replace('_', ' ', $key) }}: {{ $value }}
            @endif
        @endforeach
    </div>
    <table>
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th>{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ max(1, count($columns)) }}">No rows.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if ($truncated)
        <p class="note">Only the first {{ number_format($truncated) }} rows are shown. Export as CSV for every row.</p>
    @endif
</body>
</html>
