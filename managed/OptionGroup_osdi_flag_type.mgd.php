<?php

return [
  [
    'name' => 'OptionGroup_osdi_flag_type',
    'entity' => 'OptionGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'osdi_flag_type',
        'title' => 'OSDI Flag Type',
        'description' => 'Used by the OSDI Client (Action Network) extension',
        'data_type' => 'String',
        'is_reserved' => FALSE,
        'is_active' => TRUE,
        'is_locked' => FALSE,
        'option_value_fields' => [
          'name',
          'label',
          'description',
        ],
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_osdi_flag_type_OptionValue_Merge_incomplete',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'osdi_flag_type',
        'label' => 'Merge incomplete',
        'value' => 'merge_incomplete',
        'name' => 'Merge incomplete',
        'grouping' => NULL,
        'filter' => 0,
        'is_default' => FALSE,
        'description' => NULL,
        'is_optgroup' => FALSE,
        'is_reserved' => FALSE,
        'is_active' => TRUE,
        'component_id' => NULL,
        'domain_id' => NULL,
        'visibility_id' => NULL,
        'icon' => NULL,
        'color' => NULL,
      ],
      'match' => [
        'name',
      ],
    ],
  ],
];
