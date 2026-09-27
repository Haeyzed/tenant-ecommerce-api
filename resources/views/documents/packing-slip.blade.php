<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Packing slip {{ $order->order_number }}</title>
<style>
    @page { margin: 28px 32px; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 14px; color: #374151; margin: 0; }
    h1 { font-size: 28px; font-weight: bold; line-height: 1; letter-spacing: -0.3px; margin: 0; color: #111827; }
    h2 { font-size: 12px; font-weight: bold; letter-spacing: 0.4px; text-transform: uppercase; color: #6b7280; margin: 36px 0 10px; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }
    .right { text-align: right; }

    /* ---------- Header ---------- */
    .header td { width: 50%; }
    .sub { font-size: 14px; color: #6b7280; margin-top: 10px; }
    .ship { text-align: right; font-size: 14px; line-height: 23px; }
    .ship .to { font-size: 19px; font-weight: bold; color: #111827; line-height: 1.3; margin-bottom: 6px; }
    .ship .to span { display: block; font-size: 11.5px; font-weight: bold; color: #6b7280; letter-spacing: 0.4px; text-transform: uppercase; margin-bottom: 9px; }

    /* ---------- Items ---------- */
    .items th {
        font-size: 12px; font-weight: bold; color: #6b7280; text-transform: uppercase; letter-spacing: 0.3px;
        text-align: left; padding: 0 12px 9px; border-bottom: 2px solid #d1d5db;
    }
    .items td { font-size: 14px; padding: 12px; border-bottom: 1px solid #e5e7eb; }
    .items th:first-child, .items td:first-child { padding-left: 0; }
    .items th:last-child, .items td:last-child { padding-right: 0; }
    .items th.right, .items td.right { text-align: right; }
    .items .check { width: 32px; font-family: DejaVu Sans, sans-serif; font-size: 16px; line-height: 1; color: #9ca3af; }
    .items td.name { font-weight: bold; color: #111827; }
    .items td.sku { color: #6b7280; }
    .items td.qty { font-size: 16px; font-weight: bold; color: #111827; }
</style>
</head>
<body>
<table class="header">
    <tr>
        <td>
            <h1>Packing slip{{ $is_test ? ' (TEST)' : '' }}</h1>
            <div class="sub">{{ $store_name }} · Order {{ $order->order_number }}</div>
        </td>
        <td class="ship">
            @if ($order->customer_name)<div class="to"><span>Ship to:</span> {{ $order->customer_name }}</div>@endif
            @foreach ($shipping_address as $line)
                <div>{{ $line }}</div>
            @endforeach
        </td>
    </tr>
</table>

@foreach ($groups as $warehouse => $lines)
    <h2>{{ $warehouse }}</h2>
    <table class="items">
        <thead><tr><th class="check"></th><th>Item</th><th>SKU</th><th class="right">Qty</th></tr></thead>
        <tbody>
        @foreach ($lines as $line)
            <tr><td class="check">☐</td><td class="name">{{ $line['name'] }}</td><td class="sku">{{ $line['sku'] }}</td><td class="right qty">{{ $line['quantity'] }}</td></tr>
        @endforeach
        </tbody>
    </table>
@endforeach
</body>
</html>