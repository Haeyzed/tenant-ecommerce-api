<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice {{ $number }}</title>
<style>
    @page { margin: 28px 32px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1f2937; }
    h1 { font-size: 20px; margin: 0; color: {{ $color }}; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; }
    .muted { color: #6b7280; }
    .right { text-align: right; }
    .lines { margin-top: 18px; }
    .lines th { background: {{ $color }}; color: #fff; padding: 6px; text-align: left; font-weight: bold; }
    .lines td { padding: 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
    .totals { width: 45%; margin-left: 55%; margin-top: 12px; }
    .totals td { padding: 4px 6px; }
    .totals .grand td { border-top: 2px solid {{ $color }}; font-weight: bold; font-size: 12px; }
    .block { margin-top: 16px; }
    .watermark { position: fixed; top: 38%; left: 18%; font-size: 110px; color: rgba(220, 38, 38, 0.12); transform: rotate(-30deg); }
</style>
</head>
<body>
@if ($is_test)
    <div class="watermark">TEST</div>
@endif

@if ($template->header_text)
    <p>{{ $template->header_text }}</p>
@endif

<table class="head">
    <tr>
        <td>
            @if ($store['logo'])
                <img src="{{ $store['logo'] }}" alt="" style="height: {{ $template->logo_height ?? 48 }}px;@if ($template->logo_width) width: {{ $template->logo_width }}px;@endif">
            @endif
            <div><strong>{{ $store['name'] }}</strong></div>
            @foreach ($store['address'] as $line)
                <div class="muted">{{ $line }}</div>
            @endforeach
            @if ($store['email'])<div class="muted">{{ $store['email'] }}</div>@endif
            @if ($store['phone'])<div class="muted">{{ $store['phone'] }}</div>@endif
            @if (($template->show_registration_number || $gst) && $store['registration'])
                <div>{{ $gst ? 'GSTIN' : 'VAT No.' }}: {{ $store['registration'] }}</div>
            @endif
        </td>
        <td class="right">
            <h1>{{ $is_test ? 'TEST INVOICE' : 'INVOICE' }}</h1>
            @if ($template->show_generated_invoice_number)
                <div>No. {{ $number }}</div>
            @endif
            @if ($template->show_reference_number)
                <div class="muted">Order {{ $order->order_number }}</div>
            @endif
            <div class="muted">{{ $date }}</div>
            @if ($barcode)
                <div style="margin-top: 6px;"><img src="{{ $barcode }}" alt="" style="height: 36px;"></div>
            @endif
        </td>
    </tr>
</table>

@if ($template->show_bill_to_info)
    <div class="block">
        <div class="muted">Bill to</div>
        @if ($template->show_customer_name && $bill_to['name'])<div><strong>{{ $bill_to['name'] }}</strong></div>@endif
        @foreach ($bill_to['address'] as $line)
            <div>{{ $line }}</div>
        @endforeach
        @if ($bill_to['email'])<div>{{ $bill_to['email'] }}</div>@endif
        @if ($bill_to['phone'])<div>{{ $bill_to['phone'] }}</div>@endif
    </div>
@endif

<table class="lines">
    <thead>
    <tr>
        <th>Item</th>
        @if ($gst)<th>HSN</th>@endif
        @if ($template->show_warehouse_info)<th>Warehouse</th>@endif
        <th class="right">Qty</th>
        <th class="right">Unit price</th>
        <th class="right">Tax</th>
        <th class="right">Total</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($lines as $line)
        <tr>
            <td>
                {{ $line['name'] }}
                @if ($line['sku'])<div class="muted">SKU {{ $line['sku'] }}</div>@endif
                @if ($template->show_description && $line['description'])<div class="muted">{{ $line['description'] }}</div>@endif
                @if ($line['discount'])<div class="muted">Discount {{ $line['discount'] }}</div>@endif
            </td>
            @if ($gst)<td>{{ $line['hsn_code'] }}</td>@endif
            @if ($template->show_warehouse_info)<td>{{ $line['warehouse'] }}</td>@endif
            <td class="right">{{ $line['quantity'] }}</td>
            <td class="right">{{ $line['unit_price'] }}</td>
            <td class="right">
                {{ $line['tax'] }}
                @if ($gst)
                    @foreach ($line['tax_breakdown'] as $component => $amount)
                        <div class="muted">{{ strtoupper($component) }} {{ $amount }}</div>
                    @endforeach
                @endif
            </td>
            <td class="right">{{ $line['total'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>Subtotal</td><td class="right">{{ $totals['subtotal'] }}</td></tr>
    @if ($totals['discount'])<tr><td>Discount</td><td class="right">-{{ $totals['discount'] }}</td></tr>@endif
    <tr><td>Shipping</td><td class="right">{{ $totals['shipping'] }}</td></tr>
    @if ($gst)
        @foreach ($gst_totals as $component => $amount)
            <tr><td>{{ strtoupper($component) }}</td><td class="right">{{ $amount }}</td></tr>
        @endforeach
    @endif
    <tr><td>Tax</td><td class="right">{{ $totals['tax'] }}</td></tr>
    <tr class="grand"><td>Total</td><td class="right">{{ $totals['total'] }}</td></tr>
    @if ($template->show_payment_details)<tr><td>Paid</td><td class="right">{{ $totals['paid'] }}</td></tr>@endif
    @if ($template->show_total_due)<tr><td><strong>Balance due</strong></td><td class="right"><strong>{{ $totals['due'] }}</strong></td></tr>@endif
</table>

@if ($amount_in_words)
    <div class="block"><span class="muted">Amount in words:</span> {{ $amount_in_words }}</div>
@endif

@if ($template->show_payment_details && count($payments) > 0)
    <div class="block">
        <div class="muted">Payments</div>
        @foreach ($payments as $payment)
            <div>{{ ucfirst($payment['method']) }}: {{ $payment['amount'] }} ({{ $payment['date'] }})</div>
        @endforeach
    </div>
@endif

@if ($template->show_payment_notes && $template->payment_notes)
    <div class="block"><div class="muted">Payment notes</div>{!! nl2br(e($template->payment_notes)) !!}</div>
@endif

@if ($template->show_sales_notes && $template->sales_notes)
    <div class="block"><div class="muted">Notes</div>{!! nl2br(e($template->sales_notes)) !!}</div>
@endif

@if ($qr_code)
    <div class="block"><img src="{{ $qr_code }}" alt="" style="width: 110px; height: 110px;"></div>
@endif

@if ($template->signature_enabled)
    <div class="block" style="margin-top: 40px; width: 200px; border-top: 1px solid #9ca3af; text-align: center;" >Authorised signature</div>
@endif

@if ($template->footer_text)
    <div class="block muted">{!! nl2br(e($template->footer_text)) !!}</div>
@endif
</body>
</html>
