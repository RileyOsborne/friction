<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'topic_id',
        'played_at',
        'is_starter',
    ];

    protected $casts = [
        'played_at' => 'datetime',
        'is_starter' => 'boolean',
    ];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class)->orderBy('position');
    }

    public function topTenAnswers(): HasMany
    {
        return $this->answers()->where('is_friction', false);
    }

    public function frictionAnswers(): HasMany
    {
        return $this->answers()->where('is_friction', true);
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
    }

    /**
     * Check if category has enough answers to be used.
     *
     * @param int $minAnswers Minimum required answers (defaults to 10)
     */
    public function isComplete(int $minAnswers = 10): bool
    {
        return $this->answers()->count() >= $minAnswers;
    }

    public function frictionCount(): int
    {
        return $this->frictionAnswers()->count();
    }
}
