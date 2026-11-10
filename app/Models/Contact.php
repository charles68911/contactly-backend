<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'address',
        'category',
        'profile_image_url',
        'is_favorite',
    ];

    protected $casts = [
        'is_favorite' => 'boolean',
    ];

    // Relationship: Contact belongs to a User
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
