<?php

// The practice export (Settings → Your data; docs/spec/06). README.txt goes into
// the ZIP; the CSV headings are for spreadsheets.
return [
    'readme' => <<<'TEXT'
AstroLabe — export of the practice ":practice"
Made on :date (UTC). Format version :version.

What is inside
  practice.json        the practice's settings, members, methods and tags
  clients.json         clients with their birth data, tags and methods
  related_people.json  partners, children, parents … with their birth data
  relationships.json   links between clients and related people or other clients
  consultations.json   consultations with notes, summary and next steps (HTML)
  notes.json           notes (HTML), private ones included
  appointments.json    the calendar
  tasks.json           tasks and follow-ups
  payments.json        payments and refunds
  services.json        services and prices
  files.json           files and links; each file is in the files/ folder
  charts.json          calculated charts, as the app drew them
  portal_access.json   clients' access to the client portal (address and dates)
  csv/                 clients, consultations and payments for a spreadsheet
  files/               every file under its original name, one folder per client

How to read it
  Times are UTC (ISO 8601) unless a field says otherwise; "timezone" is the
  zone a time was entered in. Birth dates and times are exactly as entered
  (local time at the place of birth), never converted.
  Money is a whole number in the currency's smallest unit plus the currency
  code: {"amount": 4900, "currency": "EUR"} is 49.00 EUR. In the CSV files
  amounts are decimal, refunds negative.
  Deleted items are not included. Archived clients are.
  A payment without a client was kept after its client was deleted for good.

Keep this file safe: it holds your clients' personal data.

Counts
TEXT,

    'csv' => [
        'id' => 'ID',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'email' => 'Email',
        'phone' => 'Phone',
        'country' => 'Country',
        'status' => 'Status',
        'tags' => 'Tags',
        'birth_date' => 'Birth date',
        'birth_time' => 'Birth time',
        'time_accuracy' => 'Time accuracy',
        'birth_place' => 'Birth place',
        'latitude' => 'Latitude',
        'longitude' => 'Longitude',
        'birth_timezone' => 'Birth time zone',
        'created' => 'Added',
        'client_id' => 'Client ID',
        'client' => 'Client',
        'date' => 'Date',
        'time' => 'Time',
        'timezone' => 'Time zone',
        'title' => 'Title',
        'service' => 'Service',
        'duration' => 'Minutes',
        'fee' => 'Fee',
        'currency' => 'Currency',
    ],
];
