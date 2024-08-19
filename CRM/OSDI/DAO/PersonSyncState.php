<?php

/**
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 *
 * Generated from osdi-client/xml/schema/CRM/OSDI/PersonSyncState.xml
 * DO NOT EDIT.  Generated by CRM_Core_CodeGen
 * (GenCodeChecksum:e4857176e6f683d41af3f823b9577254)
 */
use CRM_OSDI_ExtensionUtil as E;

/**
 * Database access object for the PersonSyncState entity.
 */
class CRM_OSDI_DAO_PersonSyncState extends CRM_Core_DAO {
  const EXT = E::LONG_NAME;
  const TABLE_ADDED = '';

  /**
   * Static instance to hold the table name.
   *
   * @var string
   */
  public static $_tableName = 'civicrm_osdi_person_sync_state';

  /**
   * Should CiviCRM log any modifications to this table in the civicrm_log table.
   *
   * @var bool
   */
  public static $_log = TRUE;

  /**
   * Unique PersonSyncState ID
   *
   * @var int|string|null
   *   (SQL type: int unsigned)
   *   Note that values will be retrieved from the database as a string.
   */
  public $id;

  /**
   * FK to Contact
   *
   * @var int|string|null
   *   (SQL type: int unsigned)
   *   Note that values will be retrieved from the database as a string.
   */
  public $contact_id;

  /**
   * FK to OSDI Sync Profile
   *
   * @var int|string|null
   *   (SQL type: int unsigned)
   *   Note that values will be retrieved from the database as a string.
   */
  public $sync_profile_id;

  /**
   * FK to identifier field on remote system
   *
   * @var string|null
   *   (SQL type: varchar(255))
   *   Note that values will be retrieved from the database as a string.
   */
  public $remote_person_id;

  /**
   * Modification date and time of the remote person record as of the beginning of the last sync
   *
   * @var string|null
   *   (SQL type: timestamp)
   *   Note that values will be retrieved from the database as a string.
   */
  public $remote_pre_sync_modified_time;

  /**
   * Modification date and time of the remote person record at the end of the last sync
   *
   * @var string|null
   *   (SQL type: timestamp)
   *   Note that values will be retrieved from the database as a string.
   */
  public $remote_post_sync_modified_time;

  /**
   * Modification date and time of the local contact record as of the beginning of the last sync, in unix timestamp format
   *
   * @var string|null
   *   (SQL type: timestamp)
   *   Note that values will be retrieved from the database as a string.
   */
  public $local_pre_sync_modified_time;

  /**
   * Modification date and time of the local contact record at the end of the last sync
   *
   * @var string|null
   *   (SQL type: timestamp)
   *   Note that values will be retrieved from the database as a string.
   */
  public $local_post_sync_modified_time;

  /**
   * Date and time of the last sync
   *
   * @var string|null
   *   (SQL type: timestamp)
   *   Note that values will be retrieved from the database as a string.
   */
  public $sync_time;

  /**
   * 0 if local CiviCRM was the origin of the last sync, 1 if remote system was the origin
   *
   * @var int|string|null
   *   (SQL type: tinyint)
   *   Note that values will be retrieved from the database as a string.
   */
  public $sync_origin;

  /**
   * Status code of the last sync, from \Civi\Osdi\Result\Sync
   *
   * @var string|null
   *   (SQL type: varchar(32))
   *   Note that values will be retrieved from the database as a string.
   */
  public $sync_status;

  /**
   * Class constructor.
   */
  public function __construct() {
    $this->__table = 'civicrm_osdi_person_sync_state';
    parent::__construct();
  }

  /**
   * Returns localized title of this entity.
   *
   * @param bool $plural
   *   Whether to return the plural version of the title.
   */
  public static function getEntityTitle($plural = FALSE) {
    return $plural ? E::ts('OSDI Person Sync States') : E::ts('OSDI Person Sync State');
  }

  /**
   * Returns all the column names of this table
   *
   * @return array
   */
  public static function &fields() {
    if (!isset(Civi::$statics[__CLASS__]['fields'])) {
      Civi::$statics[__CLASS__]['fields'] = [
        'id' => [
          'name' => 'id',
          'type' => CRM_Utils_Type::T_INT,
          'title' => E::ts('ID'),
          'description' => E::ts('Unique PersonSyncState ID'),
          'required' => TRUE,
          'usage' => [
            'import' => TRUE,
            'export' => TRUE,
            'duplicate_matching' => TRUE,
            'token' => FALSE,
          ],
          'import' => TRUE,
          'where' => 'civicrm_osdi_person_sync_state.id',
          'export' => TRUE,
          'table_name' => 'civicrm_osdi_person_sync_state',
          'entity' => 'PersonSyncState',
          'bao' => 'CRM_OSDI_DAO_PersonSyncState',
          'localizable' => 0,
          'html' => [
            'type' => 'EntityRef',
          ],
          'readonly' => TRUE,
          'add' => NULL,
        ],
        'contact_id' => [
          'name' => 'contact_id',
          'type' => CRM_Utils_Type::T_INT,
          'title' => E::ts('Local Contact ID'),
          'description' => E::ts('FK to Contact'),
          'usage' => [
            'import' => TRUE,
            'export' => TRUE,
            'duplicate_matching' => TRUE,
            'token' => FALSE,
          ],
          'import' => TRUE,
          'where' => 'civicrm_osdi_person_sync_state.contact_id',
          'export' => TRUE,
          'table_name' => 'civicrm_osdi_person_sync_state',
          'entity' => 'PersonSyncState',
          'bao' => 'CRM_OSDI_DAO_PersonSyncState',
          'localizable' => 0,
          'FKClassName' => 'CRM_Contact_DAO_Contact',
          'html' => [
            'type' => 'EntityRef',
            'label' => E::ts("Local Contact"),
          ],
          'add' => NULL,
        ],
        'sync_profile_id' => [
          'name' => 'sync_profile_id',
          'type' => CRM_Utils_Type::T_INT,
          'title' => E::ts('OSDI Sync Profile ID'),
          'description' => E::ts('FK to OSDI Sync Profile'),
          'usage' => [
            'import' => TRUE,
            'export' => TRUE,
            'duplicate_matching' => TRUE,
            'token' => FALSE,
          ],
          'import' => TRUE,
          'where' => 'civicrm_osdi_person_sync_state.sync_profile_id',
          'export' => TRUE,
          'table_name' => 'civicrm_osdi_person_sync_state',
          'entity' => 'PersonSyncState',
          'bao' => 'CRM_OSDI_DAO_PersonSyncState',
          'localizable' => 0,
          'FKClassName' => 'CRM_OSDI_DAO_SyncProfile',
          'html' => [
            'type' => 'EntityRef',
            'label' => E::ts("Sync Profile"),
          ],
          'add' => NULL,
        ],
        'remote_person_id' => [
          'name' => 'remote_person_id',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => E::ts('Remote Person Identifier'),
          'description' => E::ts('FK to identifier field on remote system'),
          'maxlength' => 255,
          'size' => CRM_Utils_Type::HUGE,
          'usage' => [
            'import' => TRUE,
            'export' => TRUE,
            'duplicate_matching' => TRUE,
            'token' => FALSE,
          ],
          'import' => TRUE,
          'where' => 'civicrm_osdi_person_sync_state.remote_person_id',
          'export' => TRUE,
          'default' => NULL,
          'table_name' => 'civicrm_osdi_person_sync_state',
          'entity' => 'PersonSyncState',
          'bao' => 'CRM_OSDI_DAO_PersonSyncState',
          'localizable' => 0,
          'html' => [
            'type' => 'Text',
          ],
          'add' => NULL,
        ],
        'remote_pre_sync_modified_time' => [
          'name' => 'remote_pre_sync_modified_time',
          'type' => CRM_Utils_Type::T_TIMESTAMP,
          'title' => E::ts('Remote Pre-Sync Mod Time'),
          'description' => E::ts('Modification date and time of the remote person record as of the beginning of the last sync'),
          'usage' => [
            'import' => TRUE,
            'export' => TRUE,
            'duplicate_matching' => TRUE,
            'token' => FALSE,
          ],
          'import' => TRUE,
          'where' => 'civicrm_osdi_person_sync_state.remote_pre_sync_modified_time',
          'export' => TRUE,
          'default' => NULL,
          'table_name' => 'civicrm_osdi_person_sync_state',
          'entity' => 'PersonSyncState',
          'bao' => 'CRM_OSDI_DAO_PersonSyncState',
          'localizable' => 0,
          'html' => [
            'type' => 'Select Date',
          ],
          'add' => NULL,
        ],
        'remote_post_sync_modified_time' => [
          'name' => 'remote_post_sync_modified_time',
          'type' => CRM_Utils_Type::T_TIMESTAMP,
          'title' => E::ts('Remote Post-Sync Mod Time'),
          'description' => E::ts('Modification date and time of the remote person record at the end of the last sync'),
          'usage' => [
            'import' => TRUE,
            'export' => TRUE,
            'duplicate_matching' => TRUE,
            'token' => FALSE,
          ],
          'import' => TRUE,
          'where' => 'civicrm_osdi_person_sync_state.remote_post_sync_modified_time',
          'export' => TRUE,
          'default' => NULL,
          'table_name' => 'civicrm_osdi_person_sync_state',
          'entity' => 'PersonSyncState',
          'bao' => 'CRM_OSDI_DAO_PersonSyncState',
          'localizable' => 0,
          'html' => [
            'type' => 'Select Date',
          ],
          'add' => NULL,
        ],
        'local_pre_sync_modified_time' => [
          'name' => 'local_pre_sync_modified_time',
          'type' => CRM_Utils_Type::T_TIMESTAMP,
          'title' => E::ts('Local Pre-Sync Mod Time'),
          'description' => E::ts('Modification date and time of the local contact record as of the beginning of the last sync, in unix timestamp format'),
          'usage' => [
            'import' => TRUE,
            'export' => TRUE,
            'duplicate_matching' => TRUE,
            'token' => FALSE,
          ],
          'import' => TRUE,
          'where' => 'civicrm_osdi_person_sync_state.local_pre_sync_modified_time',
          'export' => TRUE,
          'default' => NULL,
          'table_name' => 'civicrm_osdi_person_sync_state',
          'entity' => 'PersonSyncState',
          'bao' => 'CRM_OSDI_DAO_PersonSyncState',
          'localizable' => 0,
          'html' => [
            'type' => 'Select Date',
          ],
          'add' => NULL,
        ],
        'local_post_sync_modified_time' => [
          'name' => 'local_post_sync_modified_time',
          'type' => CRM_Utils_Type::T_TIMESTAMP,
          'title' => E::ts('Local Post-Sync Mod Time'),
          'description' => E::ts('Modification date and time of the local contact record at the end of the last sync'),
          'usage' => [
            'import' => TRUE,
            'export' => TRUE,
            'duplicate_matching' => TRUE,
            'token' => FALSE,
          ],
          'import' => TRUE,
          'where' => 'civicrm_osdi_person_sync_state.local_post_sync_modified_time',
          'export' => TRUE,
          'default' => NULL,
          'table_name' => 'civicrm_osdi_person_sync_state',
          'entity' => 'PersonSyncState',
          'bao' => 'CRM_OSDI_DAO_PersonSyncState',
          'localizable' => 0,
          'html' => [
            'type' => 'Select Date',
          ],
          'add' => NULL,
        ],
        'sync_time' => [
          'name' => 'sync_time',
          'type' => CRM_Utils_Type::T_TIMESTAMP,
          'title' => E::ts('Sync Time'),
          'description' => E::ts('Date and time of the last sync'),
          'usage' => [
            'import' => TRUE,
            'export' => TRUE,
            'duplicate_matching' => TRUE,
            'token' => FALSE,
          ],
          'import' => TRUE,
          'where' => 'civicrm_osdi_person_sync_state.sync_time',
          'export' => TRUE,
          'default' => NULL,
          'table_name' => 'civicrm_osdi_person_sync_state',
          'entity' => 'PersonSyncState',
          'bao' => 'CRM_OSDI_DAO_PersonSyncState',
          'localizable' => 0,
          'html' => [
            'type' => 'Select Date',
          ],
          'add' => NULL,
        ],
        'sync_origin' => [
          'name' => 'sync_origin',
          'type' => CRM_Utils_Type::T_INT,
          'title' => E::ts('Origin of Last Sync'),
          'description' => E::ts('0 if local CiviCRM was the origin of the last sync, 1 if remote system was the origin'),
          'usage' => [
            'import' => TRUE,
            'export' => TRUE,
            'duplicate_matching' => TRUE,
            'token' => FALSE,
          ],
          'import' => TRUE,
          'where' => 'civicrm_osdi_person_sync_state.sync_origin',
          'export' => TRUE,
          'default' => NULL,
          'table_name' => 'civicrm_osdi_person_sync_state',
          'entity' => 'PersonSyncState',
          'bao' => 'CRM_OSDI_DAO_PersonSyncState',
          'localizable' => 0,
          'html' => [
            'type' => 'Radio',
          ],
          'pseudoconstant' => [
            'callback' => '\Civi\Osdi\PersonSyncState::syncOriginPseudoConstant',
            'prefetch' => 'true',
          ],
          'add' => NULL,
        ],
        'sync_status' => [
          'name' => 'sync_status',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => E::ts('Status of Last Sync'),
          'description' => E::ts('Status code of the last sync, from \Civi\Osdi\Result\Sync'),
          'maxlength' => 32,
          'size' => CRM_Utils_Type::MEDIUM,
          'usage' => [
            'import' => TRUE,
            'export' => TRUE,
            'duplicate_matching' => TRUE,
            'token' => FALSE,
          ],
          'import' => TRUE,
          'where' => 'civicrm_osdi_person_sync_state.sync_status',
          'export' => TRUE,
          'default' => NULL,
          'table_name' => 'civicrm_osdi_person_sync_state',
          'entity' => 'PersonSyncState',
          'bao' => 'CRM_OSDI_DAO_PersonSyncState',
          'localizable' => 0,
          'html' => [
            'type' => 'Select',
          ],
          'pseudoconstant' => [
            'callback' => '\Civi\Osdi\Result\Sync::getAllStatusCodes',
          ],
          'add' => NULL,
        ],
      ];
      CRM_Core_DAO_AllCoreTables::invoke(__CLASS__, 'fields_callback', Civi::$statics[__CLASS__]['fields']);
    }
    return Civi::$statics[__CLASS__]['fields'];
  }

  /**
   * Returns the list of fields that can be imported
   *
   * @param bool $prefix
   *
   * @return array
   */
  public static function &import($prefix = FALSE) {
    $r = CRM_Core_DAO_AllCoreTables::getImports(__CLASS__, 'osdi_person_sync_state', $prefix, []);
    return $r;
  }

  /**
   * Returns the list of fields that can be exported
   *
   * @param bool $prefix
   *
   * @return array
   */
  public static function &export($prefix = FALSE) {
    $r = CRM_Core_DAO_AllCoreTables::getExports(__CLASS__, 'osdi_person_sync_state', $prefix, []);
    return $r;
  }

  /**
   * Returns the list of indices
   *
   * @param bool $localize
   *
   * @return array
   */
  public static function indices($localize = TRUE) {
    $indices = [
      'index_contact_id' => [
        'name' => 'index_contact_id',
        'field' => [
          0 => 'contact_id',
        ],
        'localizable' => FALSE,
        'sig' => 'civicrm_osdi_person_sync_state::0::contact_id',
      ],
      'index_sync_profile_id' => [
        'name' => 'index_sync_profile_id',
        'field' => [
          0 => 'sync_profile_id',
        ],
        'localizable' => FALSE,
        'sig' => 'civicrm_osdi_person_sync_state::0::sync_profile_id',
      ],
      'index_remote_person_id' => [
        'name' => 'index_remote_person_id',
        'field' => [
          0 => 'remote_person_id',
        ],
        'localizable' => FALSE,
        'sig' => 'civicrm_osdi_person_sync_state::0::remote_person_id',
      ],
      'index_remote_pre_sync_modified_time' => [
        'name' => 'index_remote_pre_sync_modified_time',
        'field' => [
          0 => 'remote_pre_sync_modified_time',
        ],
        'localizable' => FALSE,
        'sig' => 'civicrm_osdi_person_sync_state::0::remote_pre_sync_modified_time',
      ],
      'index_remote_post_sync_modified_time' => [
        'name' => 'index_remote_post_sync_modified_time',
        'field' => [
          0 => 'remote_post_sync_modified_time',
        ],
        'localizable' => FALSE,
        'sig' => 'civicrm_osdi_person_sync_state::0::remote_post_sync_modified_time',
      ],
      'index_local_pre_sync_modified_time' => [
        'name' => 'index_local_pre_sync_modified_time',
        'field' => [
          0 => 'local_pre_sync_modified_time',
        ],
        'localizable' => FALSE,
        'sig' => 'civicrm_osdi_person_sync_state::0::local_pre_sync_modified_time',
      ],
      'index_local_post_sync_modified_time' => [
        'name' => 'index_local_post_sync_modified_time',
        'field' => [
          0 => 'local_post_sync_modified_time',
        ],
        'localizable' => FALSE,
        'sig' => 'civicrm_osdi_person_sync_state::0::local_post_sync_modified_time',
      ],
      'index_sync_time' => [
        'name' => 'index_sync_time',
        'field' => [
          0 => 'sync_time',
        ],
        'localizable' => FALSE,
        'sig' => 'civicrm_osdi_person_sync_state::0::sync_time',
      ],
      'index_sync_status' => [
        'name' => 'index_sync_status',
        'field' => [
          0 => 'sync_status',
        ],
        'localizable' => FALSE,
        'sig' => 'civicrm_osdi_person_sync_state::0::sync_status',
      ],
    ];
    return ($localize && !empty($indices)) ? CRM_Core_DAO_AllCoreTables::multilingualize(__CLASS__, $indices) : $indices;
  }

}
