<?php

// Email to the operator (App\Support\Operations\OperatorAlerts). Never a
// message text, request data or anything about clients — those stay in the log.
return [
    'when' => 'When: :time UTC',

    'exception' => [
        'subject' => '[:app] Server error: :type',
        'type' => 'Error: :type',
        'where' => 'Where: :where',
        'request' => 'Request: :method :path',
        'console' => 'Request: none (command or queued job)',
        'user' => 'Signed-in user id: :id',
        'log' => 'The full error is in the server log (storage/logs). The same error is emailed at most once an hour.',
    ],

    'health' => [
        'subject' => '[:app] Health check failing',
        'failing' => 'Failing: :checks',
        'failed_jobs' => '{1} 1 queued job failed since the last check.|[2,*] :count queued jobs failed since the last check.',
        'log' => 'The reasons are in the server log; failed jobs are in the failed_jobs table (php artisan queue:failed). Repeats at most once an hour while it lasts.',
    ],

    'recovered' => [
        'subject' => '[:app] Health check passing again',
        'line' => 'Every health check passes again.',
    ],
];
