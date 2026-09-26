<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Packing slip {{ $order->order_number }}</title>
<style>
    @page { margin: 28px 32px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
    h1 { font-size: 18px; margin: 0 0 4px; }
    h2 { font-size: 13px; margin: 18px 0 6px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 6px; border-bottom: 1px solid #d1d5db; text-align: left; }
    .right { text-align: right; }
    .check { width: 24px; }
</style>
</head>
<body>
<h1>Packing slip{{ $is_test ? ' (TEST)' : '' }}</h1>
<div>{{ $store_name }} · Order {{ $order->order_number }}</div>
@if ($order->customer_name)<div>Ship to: {{ $order->customer_name }}</div>@endif
@foreach ($shipping_address as $line)
    <div>{{ $line }}</div>
@endforeach

@foreach ($groups as $warehouse => $lines)
    <h2>{{ $warehouse }}</h2>
    <table>
        <thead><tr><th class="check"></th><th>Item</th><th>SKU</th><th class="right">Qty</th></tr></thead>
        <tbody>
        @foreach ($lines as $line)
            <tr><td class="check">☐</td><td>{{ $line['name'] }}</td><td>{{ $line['sku'] }}</td><td class="right">{{ $line['quantity'] }}</td></tr>
        @endforeach
        </tbody>
    </table>
@endforeach
</body>
</html>
