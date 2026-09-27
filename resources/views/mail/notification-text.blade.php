{{-- Plain-text part of mail.notification (TemplatedMail). --}}
{!! $subject !!}

@if (! empty($highlight))
{!! $highlightLabel ?? $eyebrow ?? '' !!}: {!! $highlight !!}

@endif
{!! $bodyText !!}

@if (! empty($actionUrl))
{!! $actionText !!}: {!! $actionUrl !!}

@endif
--
{!! $brandName !!}@if (! empty($footerText)) · {!! $footerText !!}@endif

