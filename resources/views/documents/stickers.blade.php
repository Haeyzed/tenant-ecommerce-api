<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Labels</title>
<style>
    @page { margin: 0; }
    body { margin: 0; font-family: 'DejaVu Sans', sans-serif; color: #000; }
    .sheet { position: relative; page-break-after: always; }
    .sheet:last-child { page-break-after: auto; }
    /* Plain blocks: dompdf ignores the width of an absolutely positioned table. */
    .label { position: absolute; overflow: hidden; text-align: center; line-height: 1.15; }
    .label-inner { padding: 0.04in 0.06in; }
    .label img { display: block; margin: 3px auto 1px; }
    .business { font-weight: bold; letter-spacing: 0.06em; text-transform: uppercase; margin-bottom: 1px; }
    .name { line-height: 1.1; letter-spacing: -0.01em; }
    .brand, .size { font-weight: normal; }
    .code { letter-spacing: 0.12em; }
    .price { margin-top: 2px; }
    .price strong { font-weight: bold; letter-spacing: -0.01em; }
    .strike { text-decoration: line-through; font-weight: 400; margin-right: 3px; }
</style>
</head>
<body>
@php
    $w = (float) $setting->sticker_width_inches;
    $h = (float) $setting->sticker_height_inches;
    $perRow = $dymo ? 1 : max(1, $setting->stickers_per_row);
@endphp
@foreach ($sheets as $sheet)
    <div class="sheet" style="height: {{ ($dymo ? $h : (float) $setting->paper_height_inches) - 0.02 }}in;">
        @foreach ($sheet as $index => $label)
            @php
                $row = intdiv($index, $perRow);
                $col = $index % $perRow;
                $top = $dymo ? 0 : (float) $setting->top_margin_inches + $row * ($h + (float) $setting->row_distance_inches);
                $left = $dymo ? 0 : (float) $setting->left_margin_inches + $col * ($w + (float) $setting->column_distance_inches);
            @endphp
            <div class="label" style="top: {{ $top }}in; left: {{ $left }}in; width: {{ $w }}in; height: {{ $h }}in;">
                <div class="label-inner">
                @if ($label['business'])<div class="business" style="font-size: {{ $options['business_name_font_size'] }}px;">{{ $label['business'] }}</div>@endif
                @if ($label['name'])<div class="name" style="font-size: {{ $options['name_font_size'] }}px; font-weight: bold;">{{ \Illuminate\Support\Str::limit($label['name'], 48) }}</div>@endif
                @if ($label['brand'])<div class="brand" style="font-size: {{ $options['brand_font_size'] }}px;">{{ $label['brand'] }}</div>@endif
                @if ($label['size'])<div class="size" style="font-size: {{ $options['size_font_size'] }}px;">Size {{ $label['size'] }}</div>@endif
                <img src="{{ $label['barcode'] }}" alt="" style="height: {{ max(0.3, $h * 0.38) }}in; max-width: {{ max(0.5, $w - 0.2) }}in;">
                @if ($options['show_barcode_value'])<div class="code" style="font-size: 7px;">{{ $label['code'] }}</div>@endif
                @if ($label['price'])
                    <div class="price" style="font-size: {{ $options['price_font_size'] }}px;">
                        @if ($label['promotional_price'])
                            <span class="strike">{{ $label['price'] }}</span>
                            <strong style="font-size: {{ $options['promotional_price_font_size'] }}px;">{{ $label['promotional_price'] }}</strong>
                        @else
                            <strong>{{ $label['price'] }}</strong>
                        @endif
                    </div>
                @endif
                </div>
            </div>
        @endforeach
    </div>
@endforeach
</body>
</html>