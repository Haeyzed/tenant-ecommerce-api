<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Receipt {{ $number }}</title>
<style>
    @page { size: {{ $template->invoice_type === '58mm' ? '58mm' : '80mm' }} auto; margin: 2mm; }
    body { width: {{ $width }}pt; margin: 0 auto; font-family: monospace; font-size: 11px; color: #000; }
    .center { text-align: center; }
    .right { text-align: right; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; padding: 1px 0; }
    hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
</style>
</head>
<body>
@if ($is_test)<div class="center"><strong>*** TEST ***</strong></div>@endif
<div class="center">
    @if ($store['logo'])<img src="{{ $store['logo'] }}" alt="" style="max-width: 100%; height: {{ $template->logo_height ?? 36 }}px;">@endif
    <div><strong>{{ $store['name'] }}</strong></div>
    @foreach ($store['address'] as $line)<div>{{ $line }}</div>@endforeach
    @if ($store['phone'])<div>{{ $store['phone'] }}</div>@endif
    @if (($template->show_registration_number || $gst) && $store['registration'])<div>{{ $gst ? 'GSTIN' : 'VAT' }}: {{ $store['registration'] }}</div>@endif
    @if ($template->header_text)<div>{{ $template->header_text }}</div>@endif
</div>
<hr>
@if ($template->show_generated_invoice_number)<div>No. {{ $number }}</div>@endif
@if ($template->show_reference_number)<div>Order {{ $order->order_number }}</div>@endif
<div>{{ $date }}</div>
@if ($template->show_customer_name && $bill_to['name'])<div>{{ $bill_to['name'] }}</div>@endif
<hr>
<table>
    @foreach ($lines as $line)
        <tr><td colspan="2">{{ $line['name'] }}</td></tr>
        <tr><td>{{ $line['quantity'] }} x {{ $line['unit_price'] }}</td><td class="right">{{ $line['total'] }}</td></tr>
    @endforeach
</table>
<hr>
<table>
    <tr><td>Subtotal</td><td class="right">{{ $totals['subtotal'] }}</td></tr>
    @if ($totals['discount'])<tr><td>Discount</td><td class="right">-{{ $totals['discount'] }}</td></tr>@endif
    <tr><td>Tax</td><td class="right">{{ $totals['tax'] }}</td></tr>
    <tr><td><strong>Total</strong></td><td class="right"><strong>{{ $totals['total'] }}</strong></td></tr>
    @if ($template->show_payment_details)<tr><td>Paid</td><td class="right">{{ $totals['paid'] }}</td></tr>@endif
    @if ($template->show_total_due)<tr><td>Due</td><td class="right">{{ $totals['due'] }}</td></tr>@endif
</table>
@if ($amount_in_words)<div>{{ $amount_in_words }}</div>@endif
@if ($barcode)<div class="center"><img src="{{ $barcode }}" alt="" style="max-width: 100%; height: 30px;"></div>@endif
@if ($qr_code)<div class="center"><img src="{{ $qr_code }}" alt="" style="width: 90px; height: 90px;"></div>@endif
@if ($template->show_payment_notes && $template->payment_notes)<div>{!! nl2br(e($template->payment_notes)) !!}</div>@endif
@if ($template->footer_text)<hr><div class="center">{!! nl2br(e($template->footer_text)) !!}</div>@endif
</body>
</html>
