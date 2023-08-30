<?php

return [
  [
    'name' => 'OptionGroup_osdi_log_used_for',
    'entity' => 'OptionGroup',
    'cleanup' => 'always',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'osdi_log_used_for',
        'title' => 'OSDI Log Joined Entities',
        'description' => 'Used by the OSDI Client (Action Network) extension',
        'data_type' => 'String',
        'is_reserved' => TRUE,
        'is_active' => TRUE,
        'is_locked' => TRUE,
        'option_value_fields' => [
          'name',
          'label',
        ],
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_osdi_log_used_for_OptionValue_personsyncstate',
    'entity' => 'OptionValue',
    'cleanup' => 'always',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'osdi_log_used_for',
        'label' => 'Person Sync State',
        'value' => 'civicrm_osdi_person_sync_state',
        'name' => 'civicrm_osdi_person_sync_state',
        'filter' => 0,
        'is_default' => FALSE,
        'is_optgroup' => FALSE,
        'is_reserved' => TRUE,
        'is_active' => TRUE,
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_osdi_log_used_for_OptionValue_donationsyncstate',
    'entity' => 'OptionValue',
    'cleanup' => 'always',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'osdi_log_used_for',
        'label' => 'Donation Sync State',
        'value' => 'civicrm_osdi_donation_sync_state',
        'name' => 'civicrm_osdi_donation_sync_state',
        'filter' => 0,
        'is_default' => FALSE,
        'is_optgroup' => FALSE,
        'is_reserved' => TRUE,
        'is_active' => TRUE,
      ],
      'match' => [
        'name',
      ],
    ],
  ],
];
