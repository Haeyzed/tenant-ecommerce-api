<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * The owning service of an approvable module (spec §60.1). The engine drives
 * the gate; the service still decides what "approved" means. Callbacks run
 * inside the engine's transaction, after the request is resolved, so a
 * failing callback leaves the request pending.
 */
interface Approvable
{
    public function onApprovalGranted(Model $record): void;

    public function onApprovalRejected(Model $record, ?string $note): void;

    /**
     * A short human label for notifications and the inbox, e.g. "return RET-000042".
     */
    public function approvalSubject(Model $record): string;

    /**
     * Facts the module's trigger conditions are evaluated against, e.g.
     * ['amount' => '125000.0000'] for min_amount or ['days' => 3] for
     * min_days.
     *
     * @return array<string, string|int>
     */
    public function approvalFacts(Model $record): array;
}
