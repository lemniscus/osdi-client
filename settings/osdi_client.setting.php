<?php
return [
  'osdiClient.syncJobProcessId' => [
    'name'        => 'osdiClient.syncJobProcessId',
    'title'       => ts('Action Network sync PID'),
    'description' => 'Process ID of the last Action Network sync job',
    'type'        => 'Integer',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ],
  'osdiClient.syncJobActNetModTimeCutoff' => [
    'name'        => 'osdiClient.syncJobActNetModTimeCutoff',
    'title'       => ts('Action Network sync AN mod time cutoff'),
    'description' => 'Lower limit for Action Network modification datetimes in '
    . 'the last sync job, formatted like 2021-03-03T18:15:57Z',
    'type'        => 'String',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ],
  'osdiClient.syncJobCiviModTimeCutoff' => [
    'name'        => 'osdiClient.syncJobCiviModTimeCutoff',
    'title'       => ts('Action Network sync Civi mod time cutoff'),
    'description' => 'Lower limit for Civi modification datetimes in '
      . 'the last sync job',
    'type'        => 'String',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ],
  'osdiClient.syncJobStartTime' => [
    'name'        => 'osdiClient.syncJobStartTime',
    'title'       => ts('Action Network sync start time'),
    'description' => ts('Start time of last Action Network sync job'),
    'type'        => 'String',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ],
  'osdiClient.syncJobEndTime' => [
    'name'        => 'osdiClient.syncJobEndTime',
    'title'       => ts('Action Network sync end time'),
    'description' => ts('End time of last Action Network sync job'),
    'type'        => 'String',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ],
];
