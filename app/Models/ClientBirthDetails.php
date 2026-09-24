<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client's birth data, the only source of truth for the client's charts.
 */
class ClientBirthDetails extends BirthDetails
{
    protected $table = 'client_birth_details';

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
