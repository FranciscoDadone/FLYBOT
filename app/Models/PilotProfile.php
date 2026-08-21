<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Who the pilot is, remembered so the bot asks once.
 *
 * @property int $id
 * @property string $phone
 * @property string|null $name
 * @property string|null $licence
 * @property string|null $filed_by
 * @property string|null $default_operator
 * @property string|null $default_performance
 */
class PilotProfile extends Model
{
    protected $fillable = [
        'phone',
        'name',
        'licence',
        'filed_by',
        'default_operator',
        'default_performance',
    ];

    /**
     * Item 19's "pilot in command": the name and the licence, as one line.
     */
    public function pilotInCommand(): ?string
    {
        $line = trim(implode(' ', array_filter([$this->name, $this->licence])));

        return $line === '' ? null : $line;
    }
}
