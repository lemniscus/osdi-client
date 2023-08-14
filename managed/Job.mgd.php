<?php

return [
  [
    'name' => 'Job_osdiclientcleanuplogtable',
    'entity' => 'Job',
    'cleanup' => 'always',
    'update' => 'never',
    'match' => [
      'api_action',
    ],
    'params' => [
      'version' => 4,
      'values' => [
        'domain_id' => 1,
        'run_frequency' => 'Hourly',
        'last_run' => NULL,
        'scheduled_run_date' => NULL,
        'name' => 'Action Network Job 0: Clean up log table',
        'description' => 'Remove old rows from the OSDI log table',
        'api_entity' => 'Job',
        'api_action' => 'osdiclientcleanuplogtable',
        'is_active' => FALSE,
      ],
    ],
  ],
  [
    'name' => 'Job_osdiclientprocessqueue',
    'entity' => 'Job',
    'cleanup' => 'always',
    'update' => 'never',
    'match' => [
      'api_action',
    ],
    'params' => [
      'version' => 4,
      'values' => [
        'domain_id' => 1,
        'run_frequency' => 'Always',
        'last_run' => NULL,
        'scheduled_run_date' => NULL,
        'name' => 'Action Network Job 1: Process Queue',
        'description' => 'Process "immediate sync" tasks that have been queued since the last job run',
        'api_entity' => 'Job',
        'api_action' => 'osdiclientprocessqueue',
        'parameters' => 'sync_profile_id=1',
        'is_active' => FALSE,
      ],
    ],
  ],
  [
    'name' => 'Job_osdiclientbatchsynccontacts',
    'entity' => 'Job',
    'cleanup' => 'always',
    'update' => 'never',
    'match' => [
      'api_action',
    ],
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Action Network Job 2: Batch Sync People',
        'api_action' => 'osdiclientbatchsynccontacts',
        'domain_id' => 1,
        'run_frequency' => 'Always',
        'last_run' => NULL,
        'scheduled_run_date' => NULL,
        'description' => 'Sync contact/person records in one or both directions',
        'api_entity' => 'Job',
        'parameters' => "sync_profile_id=1\norigin=remote,local",
        'is_active' => FALSE,
      ],
    ],
  ],
  [
    'name' => 'Job_osdiclientbatchsyncdonations',
    'entity' => 'Job',
    'cleanup' => 'always',
    'update' => 'never',
    'match' => [
      'api_action',
    ],
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Action Network Job 3: Batch Sync People & Donations',
        'api_action' => 'osdiclientbatchsyncdonations',
        'domain_id' => 1,
        'run_frequency' => 'Always',
        'last_run' => NULL,
        'scheduled_run_date' => NULL,
        'description' => 'Sync contact/person AND contribution/donation records in one or both directions',
        'api_entity' => 'Job',
        'parameters' => "sync_profile_id=1\norigin=remote,local",
        'is_active' => FALSE,
      ],
    ],
  ],
  [
    'name' => 'Job_osdiclientbatchsynctaggings',
    'entity' => 'Job',
    'cleanup' => 'always',
    'update' => 'never',
    'match' => [
      'api_action',
    ],
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Action Network Job 4: Batch Sync Taggings',
        'api_action' => 'osdiclientbatchsynctaggings',
        'domain_id' => 1,
        'run_frequency' => 'Always',
        'last_run' => NULL,
        'scheduled_run_date' => NULL,
        'description' => 'Sync taggings in one or both directions (tags must already be mirrored between Civi and Action Network)',
        'api_entity' => 'Job',
        'parameters' => "sync_profile_id=1\norigin=remote",
        'is_active' => FALSE,
      ],
    ],
  ],
];