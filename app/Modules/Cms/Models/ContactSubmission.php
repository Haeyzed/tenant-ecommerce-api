<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use Illuminate\Support\Carbon;

/**
 * A message from the public contact form (spec §24.7). Personal data,
 * deleted 24 months after creation.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $subject
 * @property string $message
 * @property string $status new | read | archived
 * @property int|null $customer_id
 * @property int|null $handled_by_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
class ContactSubmission extends CmsModel
{
    public const array STATUSES = ['new', 'read', 'archived'];

    protected $table = 'contact_submissions';

    protected $fillable = ['name', 'email', 'phone', 'subject', 'message', 'customer_id', 'ip_address', 'user_agent'];

    protected $hidden = ['ip_address', 'user_agent'];
}
