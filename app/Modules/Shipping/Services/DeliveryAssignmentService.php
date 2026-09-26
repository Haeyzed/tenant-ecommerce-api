<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Services;

use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Shipping\Models\DeliveryAssignment;
use App\Modules\Shipping\Models\Driver;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Shared\Exceptions\ApiException;
use App\Shared\Media\StorageQuota;
use App\Shared\Media\UploadRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * In-house deliveries (spec §36.2, §36.3). Each step moves the shipment
 * through ShipmentService, which derives the order status.
 */
final readonly class DeliveryAssignmentService
{
    public function __construct(
        private ShipmentService $shipments,
        private NotificationDispatchService $notifications,
        private StorageQuota $quota,
    ) {}

    public function assignDriver(Shipment $shipment, Driver $driver): DeliveryAssignment
    {
        if ($shipment->fulfillment_type !== ShippingMethod::IN_HOUSE) {
            throw ApiException::unprocessable('shipment_not_in_house', 'Only in-house shipments get a driver.');
        }

        if (! $driver->isActive()) {
            throw ApiException::unprocessable('driver_inactive', 'The driver is inactive.');
        }

        if ($shipment->status !== Shipment::PENDING) {
            throw ApiException::unprocessable('shipment_not_pending', 'Only a pending shipment can be assigned.');
        }

        return DB::connection('tenant')->transaction(static function () use ($shipment, $driver): DeliveryAssignment {
            Shipment::query()->whereKey($shipment->id)->lockForUpdate()->first();

            if (DeliveryAssignment::query()->where('shipment_id', $shipment->id)->exists()) {
                throw ApiException::conflict('shipment_assigned', 'This shipment already has a driver. Reassign it instead.');
            }

            $assignment = new DeliveryAssignment;
            $assignment->forceFill(['shipment_id' => $shipment->id, 'driver_id' => $driver->id, 'status' => DeliveryAssignment::ASSIGNED, 'assigned_at' => now()])->save();

            return $assignment;
        });
    }

    /**
     * Before pick-up, or after a failed delivery (the shipment returns to
     * pending).
     */
    public function reassignDriver(DeliveryAssignment $assignment, Driver $driver): DeliveryAssignment
    {
        if (! $driver->isActive()) {
            throw ApiException::unprocessable('driver_inactive', 'The driver is inactive.');
        }

        if (! in_array($assignment->status, [DeliveryAssignment::ASSIGNED, DeliveryAssignment::FAILED], true)) {
            throw ApiException::invalidTransition($assignment->status, DeliveryAssignment::ASSIGNED);
        }

        DB::connection('tenant')->transaction(function () use ($assignment, $driver): void {
            if ($assignment->status === DeliveryAssignment::FAILED) {
                $this->shipments->reopen($assignment->shipment);
            }

            $assignment->forceFill(['driver_id' => $driver->id, 'status' => DeliveryAssignment::ASSIGNED, 'assigned_at' => now(), 'picked_up_at' => null, 'delivery_notes' => null])->save();
        });

        return $assignment;
    }

    public function markPickedUp(DeliveryAssignment $assignment): DeliveryAssignment
    {
        return $this->step($assignment, [DeliveryAssignment::ASSIGNED], DeliveryAssignment::PICKED_UP, function (DeliveryAssignment $a): void {
            $a->picked_up_at = now();
            $this->shipments->markDispatched($a->shipment);
        });
    }

    public function markEnRoute(DeliveryAssignment $assignment): DeliveryAssignment
    {
        $assignment = $this->step($assignment, [DeliveryAssignment::PICKED_UP], DeliveryAssignment::EN_ROUTE, function (DeliveryAssignment $a): void {
            $this->shipments->markInTransit($a->shipment);
        });

        $order = $assignment->shipment->order;
        $this->notifications->dispatch('order.out_for_delivery', $order, ['customer_name' => (string) ($order->customer_name ?? ''), 'order_number' => $order->order_number]);

        return $assignment;
    }

    public function markDelivered(DeliveryAssignment $assignment, ?UploadedFile $proof = null): DeliveryAssignment
    {
        if ($proof !== null) {
            validator(['proof' => $proof], ['proof' => ['required', ...UploadRules::image()]])->validate();
            $this->quota->assertAllows($proof);
        }

        $assignment = $this->step($assignment, [DeliveryAssignment::PICKED_UP, DeliveryAssignment::EN_ROUTE], DeliveryAssignment::DELIVERED, function (DeliveryAssignment $a): void {
            $a->delivered_at = now();
            $this->shipments->markDelivered($a->shipment);
        });

        if ($proof !== null) {
            $assignment->addMedia($proof)->usingFileName(Str::uuid().'.'.$proof->guessExtension())->toMediaCollection('proof_of_delivery');
        }

        return $assignment;
    }

    public function markFailed(DeliveryAssignment $assignment, string $reason): DeliveryAssignment
    {
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:1000']])->validate();

        $assignment = $this->step($assignment, [DeliveryAssignment::ASSIGNED, DeliveryAssignment::PICKED_UP, DeliveryAssignment::EN_ROUTE], DeliveryAssignment::FAILED, function (DeliveryAssignment $a) use ($reason): void {
            $a->delivery_notes = $reason;
            $this->shipments->markFailed($a->shipment);
        });

        $order = $assignment->shipment->order;
        $this->notifications->dispatch('delivery.assignment_failed', $order, [
            'order_number' => $order->order_number,
            'driver_name' => $assignment->driver->name,
            'reason' => $reason,
        ]);

        return $assignment;
    }

    public function getAssignmentForShipment(Shipment $shipment): ?DeliveryAssignment
    {
        return DeliveryAssignment::query()->where('shipment_id', $shipment->id)->first();
    }

    /**
     * @param  list<string>  $from
     * @param  callable(DeliveryAssignment): void  $apply
     */
    private function step(DeliveryAssignment $assignment, array $from, string $to, callable $apply): DeliveryAssignment
    {
        return DB::connection('tenant')->transaction(static function () use ($assignment, $from, $to, $apply): DeliveryAssignment {
            /** @var DeliveryAssignment $locked */
            $locked = DeliveryAssignment::query()->with(['shipment.order', 'driver'])->whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw ApiException::invalidTransition($locked->status, $to);
            }

            $apply($locked);
            $locked->status = $to;
            $locked->save();
            $assignment->setRawAttributes($locked->getAttributes(), true);
            $assignment->setRelation('shipment', $locked->shipment->refresh());
            $assignment->setRelation('driver', $locked->driver);

            return $assignment;
        });
    }
}
