<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerAnswer extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'round_id',
        'player_id',
        'answer_id',
        'input_text',
        'points_awarded',
        'was_doubled',
        'answer_order',
        'submission_source',
    ];

    protected $casts = [
        'points_awarded' => 'integer',
        'was_doubled' => 'boolean',
        'answer_order' => 'integer',
    ];

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function answer(): BelongsTo
    {
        return $this->belongsTo(Answer::class);
    }
}
