<?php

namespace App\Enums;

/**
 * Entries on a client's timeline (docs/spec/05, "activity_events").
 *
 * Most mirror a row in another table — one entry per consultation, note, file
 * or chart — and are kept in step with it, so they can be rebuilt at any time.
 * The rest record that something changed (a profile edit, new birth data) and
 * exist only here.
 */
enum ActivityType: string
{
    case ClientCreated = 'client_created';
    case ClientUpdated = 'client_updated';
    case ClientArchived = 'client_archived';
    case ClientRestored = 'client_restored';
    case BirthDetailsUpdated = 'birth_details_updated';
    case ChartCalculated = 'chart_calculated';
    case Consultation = 'consultation';
    case Note = 'note';
    case File = 'file';

    /** Mirrors a row elsewhere and is rebuilt from it. */
    public function isProjection(): bool
    {
        return in_array($this, [self::ClientCreated, self::ChartCalculated, self::Consultation, self::Note, self::File], true);
    }

    /** The timeline filter this entry appears under (docs/spec/02, "Vremenska linija klijenta"). */
    public function category(): string
    {
        return match ($this) {
            self::Consultation => 'consultations',
            self::Note => 'notes',
            self::File => 'files',
            self::ChartCalculated, self::BirthDetailsUpdated => 'charts',
            default => 'profile',
        };
    }

    /**
     * @return list<self>
     */
    public static function inCategory(string $category): array
    {
        return array_values(array_filter(self::cases(), fn (self $type) => $type->category() === $category));
    }
}
