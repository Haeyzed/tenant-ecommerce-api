<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Labels</title>
<style>
    @page { margin: 0; }
    body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #000; }
    .sheet { position: relative; page-break-after: always; }
    .sheet:last-child { page-break-after: auto; }
    .label { position: absolute; overflow: hidden; text-align: center; line-height: 1.15; }
    .label img { display: block; margin: 1px auto; max-width: 96%; }
    .strike { text-decoration: line-through; }
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
                @if ($label['business'])<div style="font-size: {{ $options['business_name_font_size'] }}px;">{{ $label['business'] }}</div>@endif
                @if ($label['name'])<div style="font-size: {{ $options['name_font_size'] }}px; font-weight: bold;">{{ \Illuminate\Support\Str::limit($label['name'], 48) }}</div>@endif
                @if ($label['brand'])<div style="font-size: {{ $options['brand_font_size'] }}px;">{{ $label['brand'] }}</div>@endif
                @if ($label['size'])<div style="font-size: {{ $options['size_font_size'] }}px;">Size {{ $label['size'] }}</div>@endif
                <img src="{{ $label['barcode'] }}" alt="" style="height: {{ max(0.3, $h * 0.38) }}in;">
                @if ($options['show_barcode_value'])<div style="font-size: 7px;">{{ $label['code'] }}</div>@endif
                @if ($label['price'])
                    <div style="font-size: {{ $options['price_font_size'] }}px;">
                        @if ($label['promotional_price'])
                            <span class="strike">{{ $label['price'] }}</span>
                            <strong style="font-size: {{ $options['promotional_price_font_size'] }}px;">{{ $label['promotional_price'] }}</strong>
                        @else
                            <strong>{{ $label['price'] }}</strong>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endforeach
</body>
</html>
