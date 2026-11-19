<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The operator's wording of one notification text (Admin → Notifications): a
 * translation key in one language and the text that replaces the code's.
 * Read through MessageTexts; written only by the admin's notification screen.
 *
 * @property string $locale
 * @property string $key
 * @property string $value
 * @property int|null $updated_by
 */
class MessageText extends Model
{
    protected $guarded = ['id'];
}
