<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $total
 * @property string|null $correlation_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class NotificationBatch extends Model
{
    use HasUuids;

    protected $fillable = ['total', 'correlation_id'];

    /**
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'batch_id');
    }
}
