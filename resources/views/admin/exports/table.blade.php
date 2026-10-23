<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 24px 22px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111827; }
        .head { border-bottom: 2px solid #935510; padding-bottom: 6px; margin-bottom: 8px; }
        .brand { color: #935510; font-size: 14px; font-weight: bold; }
        .title { font-size: 12px; font-weight: bold; margin-top: 2px; }
        .meta { color: #4b5563; font-size: 8px; margin-top: 3px; }
        .note { background: #fff7e6; border: 1px solid #f2cf7a; color: #7a4a0a; padding: 4px 6px; margin-bottom: 6px; font-size: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #935510; color: #ffffff; text-align: left; padding: 4px; font-size: 8px; text-transform: uppercase; }
        td { border-bottom: 1px solid #e5e7eb; padding: 3px 4px; vertical-align: top; word-wrap: break-word; }
        tr:nth-child(even) td { background: #faf7f2; }
        .empty { text-align: center; color: #6b7280; padding: 16px; }
    </style>
</head>
<body>
    <div class="head">
        <div class="brand">{{ config('app.name') }}</div>
        <div class="title">{{ $title }}</div>
        <div class="meta">
            Generated {{ $generatedAt }} &middot; {{ number_format($total) }} {{ \Illuminate\Support\Str::plural('record', $total) }}
            @foreach($filters as $label => $value)
                &middot; {{ $label }}: {{ $value }}
            @endforeach
        </div>
    </div>

    @if($total > $limit)
        <div class="note">This PDF shows the first {{ number_format($limit) }} of {{ number_format($total) }} records. Download Excel for the full list.</div>
    @endif

    <table>
        <thead>
            <tr>
                @foreach($columns as $column)
                    <th>{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    @foreach($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td class="empty" colspan="{{ count($columns) }}">No records match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
