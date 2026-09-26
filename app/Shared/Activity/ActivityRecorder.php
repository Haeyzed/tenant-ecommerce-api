<?php

declare(strict_types=1);

namespace App\Shared\Activity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;

/**
 * Writes "who did what" activity rows (spec §19.1) to the database that owns
 * the subject: landlord actions always to the landlord log, tenant actions to
 * the current tenant's log.
 */
final class ActivityRecorder
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function landlord(string $logName, string $description, ?Model $subject = null, array $properties = [], ?Model $causer = null): void
    {
        // A tenant actor's id means nothing in the landlord log: callers
        // acting for a tenant user record a snapshot in $properties instead.
        $user = Auth::user();
        $causer ??= $user instanceof Model && $user->getConnectionName() === 'landlord' ? $user : null;

        self::write(new LandlordActivity, $logName, $description, $subject, $properties, $causer);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function tenant(string $logName, string $description, ?Model $subject = null, array $properties = [], ?Model $causer = null): void
    {
        $causer ??= Auth::user() instanceof Model ? Auth::user() : null;

        self::write(new Activity, $logName, $description, $subject, $properties, $causer);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function write(Activity $activity, string $logName, string $description, ?Model $subject, array $properties, ?Model $causer): void
    {
        $activity->forceFill([
            'log_name' => $logName,
            'description' => $description,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'causer_type' => $causer?->getMorphClass(),
            'causer_id' => $causer?->getKey(),
            'properties' => $properties,
        ])->save();
    }
}
