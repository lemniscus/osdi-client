<?php

/**
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 *
 * Generated from osdi-client/xml/schema/CRM/OSDI/OsdiDeletion.xml
 * DO NOT EDIT.  Generated by CRM_Core_CodeGen
 * (GenCodeChecksum:6ce914ee6ff992a68612f17005bf0095)
 */
use CRM_OSDI_ExtensionUtil as E;

/**
 * Database access object for the Deletion entity.
 */
class CRM_OSDI_DAO_Deletion extends CRM_Core_DAO {
  const EXT = E::LONG_NAME;
  const TABLE_ADDED = '';

  /**
   * Static instance to hold the table name.
   *
   * @var string
   */
  public static $_tableName = 'civicrm_osdi_deletion';

  /**
   * Should CiviCRM log any modifications to this table in the civicrm_log table.
   *
   * @var bool
   */
  public static $_log = TRUE;

  /**
   * Unique OsdiDeletion ID
   *
   * @var int|string|null
   *   (SQL type: int unsigned)
   *   Note that values will be retrieved from the database as a string.
   */
  public $id;

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
  public $remote_object_id;

  /**
   * Class constructor.
   */
  public function __construct() {
    $this->__table = 'civicrm_osdi_deletion';
    parent::__construct();
  }

  /**
   * Returns localized title of this entity.
   *
   * @param bool $plural
   *   Whether to return the plural version of the title.
   */
  public static function getEntityTitle($plural = FALSE) {
    return $plural ? E::ts('OSDI Deletions') : E::ts('OSDI Deletion');
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
          'description' => E::ts('Unique OsdiDeletion ID'),
          'required' => TRUE,
          'usage' => [
            'import' => FALSE,
            'export' => FALSE,
            'duplicate_matching' => FALSE,
            'token' => FALSE,
          ],
          'where' => 'civicrm_osdi_deletion.id',
          'table_name' => 'civicrm_osdi_deletion',
          'entity' => 'Deletion',
          'bao' => 'CRM_OSDI_DAO_Deletion',
          'localizable' => 0,
          'html' => [
            'type' => 'Number',
          ],
          'readonly' => TRUE,
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
          'where' => 'civicrm_osdi_deletion.sync_profile_id',
          'export' => TRUE,
          'table_name' => 'civicrm_osdi_deletion',
          'entity' => 'Deletion',
          'bao' => 'CRM_OSDI_DAO_Deletion',
          'localizable' => 0,
          'FKClassName' => 'CRM_OSDI_DAO_SyncProfile',
          'html' => [
            'type' => 'EntityRef',
          ],
          'add' => NULL,
        ],
        'remote_object_id' => [
          'name' => 'remote_object_id',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => E::ts('Remote Entity Identifier'),
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
          'where' => 'civicrm_osdi_deletion.remote_object_id',
          'export' => TRUE,
          'default' => NULL,
          'table_name' => 'civicrm_osdi_deletion',
          'entity' => 'Deletion',
          'bao' => 'CRM_OSDI_DAO_Deletion',
          'localizable' => 0,
          'html' => [
            'type' => 'Text',
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
    $r = CRM_Core_DAO_AllCoreTables::getImports(__CLASS__, 'osdi_deletion', $prefix, []);
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
    $r = CRM_Core_DAO_AllCoreTables::getExports(__CLASS__, 'osdi_deletion', $prefix, []);
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
      'index_sync_profile_id' => [
        'name' => 'index_sync_profile_id',
        'field' => [
          0 => 'sync_profile_id',
        ],
        'localizable' => FALSE,
        'sig' => 'civicrm_osdi_deletion::0::sync_profile_id',
      ],
      'index_remote_object_id' => [
        'name' => 'index_remote_object_id',
        'field' => [
          0 => 'remote_object_id',
        ],
        'localizable' => FALSE,
        'sig' => 'civicrm_osdi_deletion::0::remote_object_id',
      ],
    ];
    return ($localize && !empty($indices)) ? CRM_Core_DAO_AllCoreTables::multilingualize(__CLASS__, $indices) : $indices;
  }

}
