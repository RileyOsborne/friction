<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $category_id
 * @property string $text
 * @property string|null $stat
 * @property int $position
 * @property int $points
 * @property bool $is_friction
 * @property-read \App\Models\Category $category
 * @property-read string $display_text
 */
class Answer extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'category_id',
        'text',
        'stat',
        'position',
        'points',
        'is_friction',
    ];

    protected $casts = [
        'position' => 'integer',
        'points' => 'integer',
        'is_friction' => 'boolean',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the display text for this answer.
     */
    public function getDisplayTextAttribute(): string
    {
        return $this->text;
    }
}
