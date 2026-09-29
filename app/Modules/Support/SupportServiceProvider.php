<?php

declare(strict_types=1);

namespace App\Modules\Support;

use App\Modules\Customers\Events\CustomerAuthenticated;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerPrivacyRegistry;
use App\Modules\Support\Models\SupportConversation;
use App\Modules\Support\Models\SupportMessage;
use App\Modules\Support\Services\SupportConversationService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Customer support wiring (spec §59): a guest who signs in takes their
 * conversations along (§59.1); personal data (§26.4) is exported and, on
 * erasure, the customer's conversations and attachments are deleted.
 */
final class SupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(CustomerPrivacyRegistry::class, static function (CustomerPrivacyRegistry $privacy): void {
            $privacy->registerEraser('support', static function (Customer $customer): void {
                SupportConversation::query()->where('customer_id', $customer->id)->with('messages.attachments')->get()
                    ->each(static function (SupportConversation $conversation): void {
                        foreach ($conversation->messages as $message) {
                            $message->attachments->each->clearMediaCollection('attachment');
                        }

                        $conversation->delete();
                    });
            });
            $privacy->registerSection('support', static fn (Customer $customer): iterable => SupportMessage::query()
                ->whereHas('conversation', static fn ($q) => $q->where('customer_id', $customer->id))
                ->where('is_internal_note', false)->with('conversation:id,channel,subject')->orderBy('id')->get()
                ->map(static fn (SupportMessage $m): array => [
                    'conversation' => $m->conversation->subject ?? $m->conversation->channel,
                    'from' => $m->fromCustomerSide() ? 'you' : 'store',
                    'body' => $m->body,
                    'sent_at' => $m->created_at->toIso8601String(),
                ]));
        });
    }

    public function boot(): void
    {
        Event::listen(CustomerAuthenticated::class, function (CustomerAuthenticated $event): void {
            if ($event->guestToken !== null) {
                $this->app->make(SupportConversationService::class)->claimGuestConversations($event->guestToken, $event->customer);
            }
        });
    }
}
