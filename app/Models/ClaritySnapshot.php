<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One day of Clarity Data Export output, as returned by the API. Stored raw
 * because Clarity keeps only 3 days; parsing happens on read
 * (App\Services\Analytics\ClarityReport), so a parser fix applies to history.
 *
 * @property \Illuminate\Support\Carbon $captured_on UTC day the numbers cover
 * @property string $dimension '' for the overall call, else OS/Device/...
 * @property array $payload
 */
class ClaritySnapshot extends Model
{
    protected $fillable = ['captured_on', 'dimension', 'payload', 'fetched_at'];

    protected function casts(): array
    {
        return [
            'captured_on' => 'date',
            'payload' => 'array',
            'fetched_at' => 'datetime',
        ];
    }
}
