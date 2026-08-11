<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'notifiable_type', 'notifiable_id', 'channel', 'event_type', 'title', 'body',
    'status', 'related_type', 'related_id', 'sent_at', 'read_at',
])]
class AppNotification extends Model
{
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'failed']);
    }
}
