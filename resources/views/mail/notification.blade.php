{{--
|--------------------------------------------------------------------------
| Notification email layout (design B) — every catalog notification
|--------------------------------------------------------------------------
| Built by App\Modules\Messaging\Mail\TemplatedMail. Wording comes from
| the editable templates; tone, highlight and button come from
| config/notifications/presentation.php; name, logo, footer and direction
| from MailBranding. $accent and $band are validated #RRGGBB values and
| $bodyHtml is already escaped. Solid colours only (many clients ignore
| opacity). Do not name a variable $message: Laravel reserves it.
--}}
@php($align = $dir === 'rtl' ? 'right' : 'left')
<!DOCTYPE html>
<html lang="en" dir="{{ $dir }}" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $subject }}</title>
<style>
    body { margin: 0; padding: 0; width: 100% !important; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table { border-collapse: collapse; mso-table-lspace: 0; mso-table-rspace: 0; }
    img { border: 0; line-height: 100%; outline: none; text-decoration: none; }
    a { color: {{ $accent }}; }
    @media only screen and (max-width: 620px) {
        .container { width: 100% !important; }
        .px { padding-left: 24px !important; padding-right: 24px !important; }
        .title { font-size: 24px !important; line-height: 30px !important; }
        .highlight { font-size: 32px !important; line-height: 38px !important; }
        .btn a { display: block !important; }
    }
</style>
</head>
<body style="margin:0;padding:0;background-color:#eef0f3;">
    {{-- Inbox preview text (hidden) --}}
    <div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#eef0f3;">
        {{ $preheader }}&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#eef0f3;">
        <tr>
            <td align="center" style="padding:32px 12px;">
                <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background-color:#ffffff;border-radius:12px;overflow:hidden;">

                    {{-- Coloured header --}}
                    <tr>
                        <td class="px" bgcolor="{{ $band }}" style="background-color:{{ $band }};padding:32px 40px 36px;text-align:{{ $align }};">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    @if (! empty($logoUrl))
                                        <td style="padding-{{ $dir === 'rtl' ? 'left' : 'right' }}:12px;vertical-align:middle;">
                                            <img src="{{ $logoUrl }}" alt="{{ $brandName }}" height="36" style="display:block;height:36px;width:auto;border-radius:6px;background-color:#ffffff;">
                                        </td>
                                    @endif
                                    <td style="vertical-align:middle;font-family:{!! $font !!};font-size:17px;font-weight:bold;color:#ffffff;">
                                        {{ $brandName }}
                                    </td>
                                </tr>
                            </table>

                            @if (! empty($highlight))
                                @if (! empty($highlightLabel ?? $eyebrow))
                                    <div style="margin-top:30px;font-family:{!! $font !!};font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#e5e7eb;">
                                        {{ $highlightLabel ?? $eyebrow }}
                                    </div>
                                @endif
                                <div class="highlight" style="margin-top:{{ empty($highlightLabel ?? $eyebrow) ? '30px' : '4px' }};font-family:{!! $font !!};font-size:40px;line-height:46px;font-weight:bold;letter-spacing:-0.5px;color:#ffffff;word-break:break-all;">
                                    {{ $highlight }}
                                </div>
                                <div style="margin-top:6px;font-family:{!! $font !!};font-size:15px;line-height:22px;color:#f3f4f6;">
                                    {{ $subject }}
                                </div>
                            @else
                                @if (! empty($eyebrow))
                                    <div style="margin-top:30px;font-family:{!! $font !!};font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#e5e7eb;">
                                        {{ $eyebrow }}
                                    </div>
                                @endif
                                <div class="title" style="margin-top:{{ empty($eyebrow) ? '30px' : '6px' }};font-family:{!! $font !!};font-size:28px;line-height:34px;font-weight:bold;letter-spacing:-0.3px;color:#ffffff;">
                                    {{ $subject }}
                                </div>
                            @endif
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td class="px" style="padding:32px 40px 16px;text-align:{{ $align }};">
                            {{ $bodyHtml }}
                        </td>
                    </tr>

                    {{-- Button --}}
                    @if (! empty($actionUrl))
                        <tr>
                            <td class="px btn" align="center" style="padding:4px 40px 36px;">
                                <!--[if mso]>
                                <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" href="{{ $actionUrl }}" style="height:48px;v-text-anchor:middle;width:260px;" arcsize="50%" stroke="f" fillcolor="#111827">
                                <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;">{{ $actionText }}</center>
                                </v:roundrect>
                                <![endif]-->
                                <!--[if !mso]><!-->
                                <a href="{{ $actionUrl }}" style="display:inline-block;background-color:#111827;color:#ffffff;font-family:{!! $font !!};font-size:15px;font-weight:bold;line-height:20px;text-decoration:none;padding:14px 38px;border-radius:999px;">
                                    {{ $actionText }} {!! $dir === 'rtl' ? '&larr;' : '&rarr;' !!}
                                </a>
                                <!--<![endif]-->
                            </td>
                        </tr>
                    @endif

                    {{-- Footer --}}
                    <tr>
                        <td class="px" align="center" bgcolor="#f7f7f8" style="background-color:#f7f7f8;padding:22px 40px;font-family:{!! $font !!};font-size:12.5px;line-height:20px;color:#6b7280;">
                            {{ $brandName }}@if (! empty($footerText)) &middot; {{ $footerText }}@endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
