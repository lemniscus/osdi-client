<?php
return [
  'osdiClient.donationBatchSyncMaxRetrievedModTime' => [
    'name'        => 'osdiClient.donationBatchSyncMaxRetrievedModTime',
    'title'       => ts('Action Network Donation sync max retrieved AN mod time'),
    'description' => 'Latest donation date retrieved from AN by the batch syncer',
    'type'        => 'String',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ],
  'osdiClient.localUtcOffset' => [
    'name'        => 'osdiClient.localUtcOffset',
    'title'       => ts('UTC offset for Action Network sync'),
    'description' => 'Time zone of Civi contributions for the purpose of sync',
    'type'        => 'String',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ],
  'osdiClient.personBatchSyncActNetModTimeCutoff' => [
    'name'        => 'osdiClient.personBatchSyncActNetModTimeCutoff',
    'title'       => ts('Action Network Person sync AN mod time cutoff'),
    'description' => 'Lower limit for Action Network person modification '
      . 'datetimes in the last sync job, formatted like 2021-03-03T18:15:57Z',
    'type'        => 'String',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ],
  'osdiClient.syncJobCiviModTimeCutoff' => [
    'name'        => 'osdiClient.syncJobCiviModTimeCutoff',
    'title'       => ts('Action Network Person sync Civi mod time cutoff'),
    'description' => 'Lower limit for Civi modification datetimes in '
      . 'the last sync job',
    'type'        => 'String',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ],
  'osdiClient.syncJobProcessId' => [
    'name'        => 'osdiClient.syncJobProcessId',
    'title'       => ts('Action Network sync PID'),
    'description' => 'Process ID of the last Action Network sync job',
    'type'        => 'Integer',
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
