<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

/**
 * Resolves how the email layout dresses one notification
 * (config/notifications/presentation.php) from its variables. Only the
 * named variables are read; a button URL must be http(s). The result is
 * small and plain, so it travels with the queued notification.
 */
final class NotificationPresentation
{
    public const array TONES = ['default', 'success', 'warning', 'danger'];

    /**
     * @param  array<string, mixed>  $variables
     * @return array{tone?: string, eyebrow?: string, highlight?: string, highlight_label?: string, action_url?: string, action_text?: string, preheader?: string}
     */
    public static function resolve(string $key, array $variables): array
    {
        // Keys contain dots, so they are looked up in the array, not by config path.
        $map = (array) (config('notifications.presentation', [])[$key] ?? []);

        if ($map === []) {
            return [];
        }

        $value = static fn (?string $name): ?string => $name !== null && isset($variables[$name]) && is_scalar($variables[$name]) && trim((string) $variables[$name]) !== ''
            ? mb_substr(trim((string) $variables[$name]), 0, 120)
            : null;

        // Full URL (never truncated), http/https only.
        $url = isset($map['action']) && is_string($variables[$map['action']] ?? null) ? trim($variables[$map['action']]) : '';
        $url = preg_match('~^https?://[^\s<>"]+$~i', $url) === 1 ? $url : null;

        $resolved = [
            'tone' => in_array($map['tone'] ?? 'default', self::TONES, true) ? ($map['tone'] ?? 'default') : 'default',
            'eyebrow' => isset($map['eyebrow']) ? (string) $map['eyebrow'] : null,
            'highlight' => $value($map['highlight'] ?? null),
            'highlight_label' => isset($map['highlight_label']) ? (string) $map['highlight_label'] : null,
            'action_url' => $url,
            'action_text' => $url !== null && isset($map['action_text']) ? (string) $map['action_text'] : null,
            'preheader' => isset($map['preheader']) ? self::fill((string) $map['preheader'], $variables) : null,
        ];

        return array_filter($resolved, static fn (?string $v): bool => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private static function fill(string $text, array $variables): string
    {
        $filled = preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', static fn (array $m): string => is_scalar($variables[$m[1]] ?? null) ? (string) $variables[$m[1]] : '', $text);

        return mb_substr(trim((string) $filled), 0, 150);
    }
}
