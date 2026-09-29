<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * One sync run's log row (spec §68.2, §69.1): items counted as they go,
 * per-item failures kept in error_details (at most 100), and the status
 * decided at the end: success, partial (some items failed) or failed (the
 * run stopped).
 */
final class SyncRun
{
    private const int MAX_ERRORS = 100;

    private int $processed = 0;

    private int $failed = 0;

    /** @var list<array<string, string>> */
    private array $errors = [];

    /**
     * @param  array<string, mixed>  $attributes  sync_type, trigger, … of the log
     */
    public function __construct(private readonly Model $log, array $attributes)
    {
        $log->forceFill([...$attributes, 'status' => 'success', 'items_processed' => 0, 'items_failed' => 0, 'started_at' => now()])->save();
    }

    public function ok(): void
    {
        $this->processed++;
    }

    public function fail(string $item, Throwable|string $reason): void
    {
        $this->processed++;
        $this->failed++;

        if (count($this->errors) < self::MAX_ERRORS) {
            $this->errors[] = ['item' => $item, 'reason' => mb_substr(self::message($reason), 0, 500)];
        }
    }

    public function failed(): int
    {
        return $this->failed;
    }

    public function finish(): Model
    {
        return $this->close($this->failed === 0 ? 'success' : 'partial');
    }

    /**
     * The run itself failed (unreachable store, rejected credentials).
     */
    public function abort(Throwable|string $reason): Model
    {
        $this->errors[] = ['item' => 'run', 'reason' => mb_substr(self::message($reason), 0, 500)];

        return $this->close('failed');
    }

    private function close(string $status): Model
    {
        $this->log->forceFill([
            'status' => $status,
            'items_processed' => $this->processed,
            'items_failed' => $this->failed,
            'error_details' => $this->errors === [] ? null : $this->errors,
            'completed_at' => now(),
        ])->save();

        return $this->log;
    }

    /**
     * Validation errors read as their first message; anything else as its
     * message (never a trace).
     */
    private static function message(Throwable|string $reason): string
    {
        if (is_string($reason)) {
            return $reason;
        }

        if ($reason instanceof ValidationException) {
            return (string) collect($reason->errors())->flatten()->first();
        }

        return $reason->getMessage() !== '' ? $reason->getMessage() : $reason::class;
    }
}
