<?php

namespace App\Support\Notifications;

/**
 * What a notification leaves in the app (InAppChannel): its kind, the practice
 * it is about (null for the account) and plain values — ids, UTC times, counts.
 * Never names or messages: the list reads those when it is shown.
 */
final readonly class InAppMessage
{
    /**
     * @param  array<string, scalar|null>  $data
     */
    public function __construct(
        public string $kind,
        public ?int $workspaceId,
        public array $data = [],
    ) {}
}
