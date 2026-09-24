<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\RelatedPersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A partner, child, parent … of one or more clients (docs/spec/02, "Povezane
 * osobe"): personal and birth data without an account, and a chart once the
 * birth data is complete. It exists through its links to clients and can
 * later become a client of its own without the data being entered again.
 */
#[Fillable(['first_name', 'last_name', 'email', 'phone'])]
class RelatedPerson extends Model
{
    /** @use HasFactory<RelatedPersonFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    protected $table = 'related_people';

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * @return HasOne<RelatedPersonBirthDetails, $this>
     */
    public function birthDetails(): HasOne
    {
        return $this->hasOne(RelatedPersonBirthDetails::class);
    }

    /**
     * Links to the clients this person belongs with.
     *
     * @return HasMany<ClientRelationship, $this>
     */
    public function relationships(): HasMany
    {
        return $this->hasMany(ClientRelationship::class);
    }

    /**
     * The client this person became, if any.
     *
     * @return BelongsTo<Client, $this>
     */
    public function convertedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'converted_client_id');
    }
}
