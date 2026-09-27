<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice {{ $number }}</title>
<style>
    @page { margin: 28px 32px; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 14px; color: #374151; margin: 0; }
    h1 { font-size: 28px; font-weight: bold; line-height: 1; letter-spacing: -0.3px; margin: 0; color: {{ $color }}; }
    p { margin: 0; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }
    .right { text-align: right; }
    .muted { color: #6b7280; }

    .header-text { color: #6b7280; margin-bottom: 24px; }

    /* ---------- Header ---------- */
    .logo { display: inline-block; background: #f7f7f8; padding: 14px; line-height: 0; }
    .invoice-title { text-align: right; padding-top: 4px; }
    .invoice-title .num { font-size: 14px; color: #6b7280; margin-top: 10px; }
    .invoice-title .num + .num { margin-top: 4px; }
    .invoice-title .barcode { margin-top: 10px; }

    /* ---------- Parties ---------- */
    .parties { margin-top: 40px; }
    .parties td { width: 50%; font-size: 14px; line-height: 23px; }
    .label { font-size: 11.5px; font-weight: bold; color: #6b7280; letter-spacing: 0.4px; text-transform: uppercase; }
    .name { font-size: 19px; font-weight: bold; color: #111827; line-height: 1.3; margin: 0 0 8px; }
    .label + .name { margin-top: 9px; }

    /* ---------- Items table ---------- */
    .lines { margin-top: 32px; }
    .lines th {
        font-size: 12px; font-weight: bold; color: {{ $color }}; text-transform: uppercase; letter-spacing: 0.3px;
        text-align: left; padding: 0 12px 9px; border-bottom: 2px solid #d1d5db;
    }
    .lines td { font-size: 14px; padding: 12px; border-bottom: 1px solid #e5e7eb; color: #374151; }
    .lines th:first-child, .lines td:first-child { padding-left: 0; }
    .lines th:last-child, .lines td:last-child { padding-right: 0; }
    .lines th.right { text-align: right; }
    .lines td.item { font-weight: bold; color: #111827; width: 38%; }
    .lines td.amount { font-weight: bold; color: #111827; }
    /* Money never breaks between the currency and the figure. */
    .lines td.right, .totals td.right { white-space: nowrap; }
    .lines .sub { font-size: 12px; font-weight: 400; color: #6b7280; margin-top: 2px; }

    /* ---------- Totals ---------- */
    .totals-wrap { margin-top: 36px; }
    .totals td { font-size: 14px; padding: 4px 0; line-height: 20px; color: #374151; }
    .totals .divider td { padding: 6px 0 0; border-bottom: 3px solid {{ $color }}; }
    .totals .grand td { padding-top: 12px; color: #111827; vertical-align: middle; }
    .totals .grand td.gl { font-size: 14px; font-weight: bold; letter-spacing: 0.3px; text-transform: uppercase; }
    .totals .grand td.gv { font-size: 21px; font-weight: bold; letter-spacing: -0.2px; }
    .totals .strong td { color: #111827; font-weight: bold; }
    .words { font-size: 11.5px; font-style: italic; color: #6b7280; margin-top: 14px; }

    /* ---------- Notes / payment ---------- */
    .bottom { margin-top: 40px; }
    .bottom td.left { padding-right: 32px; }
    .block + .block { margin-top: 18px; }
    .block p { font-size: 14px; color: #111827; line-height: 19px; margin-top: 4px; }
    .pay .label { display: block; margin-bottom: 8px; }
    .pay p { font-size: 14px; color: #111827; line-height: 19px; }

    /* ---------- Footer ---------- */
    .footer { margin-top: 46px; }
    .footer td { vertical-align: bottom; }
    .contact { font-size: 11.5px; line-height: 20px; color: #6b7280; padding-right: 24px; }
    .sig-line { display: block; width: 200px; border-top: 1px solid #9ca3af; padding-top: 6px; text-align: center; }

    .watermark { position: fixed; top: 36%; left: 0; right: 0; text-align: center; font-size: 170px; font-weight: bold; letter-spacing: 16px; color: rgba(17, 24, 39, 0.05); transform: rotate(-35deg); }
</style>
</head>
<body>
@if ($is_test)
    <div class="watermark">TEST</div>
@endif

@if ($template->header_text)
    <p class="header-text">{{ $template->header_text }}</p>
@endif

<table>
    <tr>
        <td>
            @if ($store['logo'])
                <div class="logo"><img src="{{ $store['logo'] }}" alt="" style="height: {{ $template->logo_height ?? 48 }}px;@if ($template->logo_width) width: {{ $template->logo_width }}px;@endif"></div>
            @endif
        </td>
        <td class="invoice-title">
            <h1>{{ $is_test ? 'TEST INVOICE' : 'INVOICE' }}</h1>
            @if ($template->show_generated_invoice_number)
                <div class="num">No. {{ $number }}</div>
            @endif
            @if ($template->show_reference_number)
                <div class="num">Order {{ $order->order_number }}</div>
            @endif
            <div class="num">{{ $date }}</div>
            @if ($barcode)
                <div class="barcode"><img src="{{ $barcode }}" alt="" style="height: 36px;"></div>
            @endif
        </td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td>
            <div class="name">{{ $store['name'] }}</div>
            @foreach ($store['address'] as $line)
                <div>{{ $line }}</div>
            @endforeach
            @if ($store['email'])<div>{{ $store['email'] }}</div>@endif
            @if ($store['phone'])<div>{{ $store['phone'] }}</div>@endif
            @if (($template->show_registration_number || $gst) && $store['registration'])
                <div>{{ $gst ? 'GSTIN' : 'VAT No.' }}: {{ $store['registration'] }}</div>
            @endif
        </td>
        <td class="right">
            @if ($template->show_bill_to_info)
                <div class="label">Bill to</div>
                @if ($template->show_customer_name && $bill_to['name'])<div class="name">{{ $bill_to['name'] }}</div>@endif
                @foreach ($bill_to['address'] as $line)
                    <div>{{ $line }}</div>
                @endforeach
                @if ($bill_to['email'])<div>{{ $bill_to['email'] }}</div>@endif
                @if ($bill_to['phone'])<div>{{ $bill_to['phone'] }}</div>@endif
            @endif
        </td>
    </tr>
</table>

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
            <td class="item">
                {{ $line['name'] }}
                @if ($line['sku'])<div class="sub">SKU {{ $line['sku'] }}</div>@endif
                @if ($template->show_description && $line['description'])<div class="sub">{{ $line['description'] }}</div>@endif
                @if ($line['discount'])<div class="sub">Discount {{ $line['discount'] }}</div>@endif
            </td>
            @if ($gst)<td>{{ $line['hsn_code'] }}</td>@endif
            @if ($template->show_warehouse_info)<td>{{ $line['warehouse'] }}</td>@endif
            <td class="right">{{ $line['quantity'] }}</td>
            <td class="right">{{ $line['unit_price'] }}</td>
            <td class="right">
                {{ $line['tax'] }}
                @if ($gst)
                    @foreach ($line['tax_breakdown'] as $component => $amount)
                        <div class="sub">{{ strtoupper($component) }} {{ $amount }}</div>
                    @endforeach
                @endif
            </td>
            <td class="right amount">{{ $line['total'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals-wrap">
    <tr>
        <td></td>
        <td style="width: 406px;">
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
                <tr class="divider"><td colspan="2"></td></tr>
                <tr class="grand"><td class="gl">Total</td><td class="right gv">{{ $totals['total'] }}</td></tr>
                @if ($template->show_payment_details)<tr><td>Paid</td><td class="right">{{ $totals['paid'] }}</td></tr>@endif
                @if ($template->show_total_due)<tr class="strong"><td>Balance due</td><td class="right">{{ $totals['due'] }}</td></tr>@endif
            </table>
            @if ($amount_in_words)
                <div class="words">Amount in words: {{ $amount_in_words }}</div>
            @endif
        </td>
    </tr>
</table>

<table class="bottom">
    <tr>
        <td class="left">
            @if ($template->show_payment_details && count($payments) > 0)
                <div class="block">
                    <div class="label">Payments</div>
                    @foreach ($payments as $payment)
                        <p>{{ ucfirst($payment['method']) }}: {{ $payment['amount'] }} ({{ $payment['date'] }})</p>
                    @endforeach
                </div>
            @endif
            @if ($template->show_sales_notes && $template->sales_notes)
                <div class="block">
                    <div class="label">Notes</div>
                    <p>{!! nl2br(e($template->sales_notes)) !!}</p>
                </div>
            @endif
            @if ($qr_code)
                <div class="block"><img src="{{ $qr_code }}" alt="" style="width: 110px; height: 110px;"></div>
            @endif
        </td>
        <td class="pay" style="width: 406px;">
            @if ($template->show_payment_notes && $template->payment_notes)
                <span class="label">Payment notes</span>
                <p>{!! nl2br(e($template->payment_notes)) !!}</p>
            @endif
        </td>
    </tr>
</table>

<table class="footer">
    <tr>
        <td class="contact">
            @if ($template->footer_text)
                {!! nl2br(e($template->footer_text)) !!}
            @endif
        </td>
        @if ($template->signature_enabled)
            <td style="width: 200px;">
                <span class="label sig-line">Authorised signature</span>
            </td>
        @endif
    </tr>
</table>
</body>
</html>