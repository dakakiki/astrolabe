<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A related person's birth data, in the same shape as a client's.
 */
class RelatedPersonBirthDetails extends BirthDetails
{
    protected $table = 'related_person_birth_details';

    /**
     * @return BelongsTo<RelatedPerson, $this>
     */
    public function relatedPerson(): BelongsTo
    {
        return $this->belongsTo(RelatedPerson::class);
    }
}
