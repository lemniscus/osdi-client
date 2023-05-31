<?php

/**
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 *
 * Generated from osdi-client/xml/schema/CRM/OSDI/OsdiFlag.xml
 * DO NOT EDIT.  Generated by CRM_Core_CodeGen
 * (GenCodeChecksum:567c045eb79da1887a32a65dbc54aa38)
 */
use CRM_OSDI_ExtensionUtil as E;

/**
 * Database access object for the Flag entity.
 */
class CRM_OSDI_DAO_Flag extends CRM_Core_DAO {
  const EXT = E::LONG_NAME;
  const TABLE_ADDED = '';

  /**
   * Static instance to hold the table name.
   *
   * @var string
   */
  public static $_tableName = 'civicrm_osdi_flag';

  /**
   * Should CiviCRM log any modifications to this table in the civicrm_log table.
   *
   * @var bool
   */
  public static $_log = TRUE;

  /**
   * Unique OsdiFlag ID
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
   * FK to identifier field on remote system
   *
   * @var string|null
   *   (SQL type: varchar(255))
   *   Note that values will be retrieved from the database as a string.
   */
  public $remote_object_id;

  /**
   * @var string|null
   *   (SQL type: varchar(255))
   *   Note that values will be retrieved from the database as a string.
   */
  public $flag_type;

  /**
   * Status code
   *
   * @var string|null
   *   (SQL type: varchar(255))
   *   Note that values will be retrieved from the database as a string.
   */
  public $status;

  /**
   * Description of the issue
   *
   * @var string|null
   *   (SQL type: varchar(511))
   *   Note that values will be retrieved from the database as a string.
   */
  public $message;

  /**
   * Structured data to help understand the issue
   *
   * @var string|null
   *   (SQL type: text)
   *   Note that values will be retrieved from the database as a string.
   */
  public $context;

  /**
   * When the flag was created
   *
   * @var string|null
   *   (SQL type: timestamp)
   *   Note that values will be retrieved from the database as a string.
   */
  public $created_date;

  /**
   * When the client was created or modified.
   *
   * @var string|null
   *   (SQL type: timestamp)
   *   Note that values will be retrieved from the database as a string.
   */
  public $modified_date;

  /**
   * Class constructor.
   */
  public function __construct() {
    $this->__table = 'civicrm_osdi_flag';
    parent::__construct();
  }

  /**
   * Returns localized title of this entity.
   *
   * @param bool $plural
   *   Whether to return the plural version of the title.
   */
  public static function getEntityTitle($plural = FALSE) {
    return $plural ? E::ts('Flags') : E::ts('Flag');
  }

  /**
   * Returns foreign keys and entity references.
   *
   * @return array
   *   [CRM_Core_Reference_Interface]
   */
  public static function getReferenceColumns() {
    if (!isset(Civi::$statics[__CLASS__]['links'])) {
      Civi::$statics[__CLASS__]['links'] = static::createReferenceColumns(__CLASS__);
      Civi::$statics[__CLASS__]['links'][] = new CRM_Core_Reference_Basic(self::getTableName(), 'contact_id', 'civicrm_contact', 'id');
      CRM_Core_DAO_AllCoreTables::invoke(__CLASS__, 'links_callback', Civi::$statics[__CLASS__]['links']);
    }
    return Civi::$statics[__CLASS__]['links'];
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
          'description' => E::ts('Unique OsdiFlag ID'),
          'required' => TRUE,
          'where' => 'civicrm_osdi_flag.id',
          'table_name' => 'civicrm_osdi_flag',
          'entity' => 'Flag',
          'bao' => 'CRM_OSDI_DAO_Flag',
          'localizable' => 0,
          'html' => [
            'type' => 'Number',
          ],
          'readonly' => TRUE,
          'add' => NULL,
        ],
        'contact_id' => [
          'name' => 'contact_id',
          'type' => CRM_Utils_Type::T_INT,
          'title' => E::ts('Local Contact ID'),
          'description' => E::ts('FK to Contact'),
          'import' => TRUE,
          'where' => 'civicrm_osdi_flag.contact_id',
          'export' => TRUE,
          'table_name' => 'civicrm_osdi_flag',
          'entity' => 'Flag',
          'bao' => 'CRM_OSDI_DAO_Flag',
          'localizable' => 0,
          'FKClassName' => 'CRM_Contact_DAO_Contact',
          'add' => NULL,
        ],
        'remote_object_id' => [
          'name' => 'remote_object_id',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => E::ts('Remote Entity Identifier'),
          'description' => E::ts('FK to identifier field on remote system'),
          'maxlength' => 255,
          'size' => CRM_Utils_Type::HUGE,
          'import' => TRUE,
          'where' => 'civicrm_osdi_flag.remote_object_id',
          'export' => TRUE,
          'default' => NULL,
          'table_name' => 'civicrm_osdi_flag',
          'entity' => 'Flag',
          'bao' => 'CRM_OSDI_DAO_Flag',
          'localizable' => 0,
          'add' => NULL,
        ],
        'flag_type' => [
          'name' => 'flag_type',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => E::ts('Flag Type'),
          'maxlength' => 255,
          'size' => CRM_Utils_Type::HUGE,
          'import' => TRUE,
          'where' => 'civicrm_osdi_flag.flag_type',
          'export' => TRUE,
          'default' => NULL,
          'table_name' => 'civicrm_osdi_flag',
          'entity' => 'Flag',
          'bao' => 'CRM_OSDI_DAO_Flag',
          'localizable' => 0,
          'html' => [
            'type' => 'Radio',
          ],
          'pseudoconstant' => [
            'optionGroupName' => 'osdi_flag_type',
            'optionEditPath' => 'civicrm/admin/options/osdi_flag_type',
          ],
          'add' => NULL,
        ],
        'status' => [
          'name' => 'status',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => E::ts('Status'),
          'description' => E::ts('Status code'),
          'maxlength' => 255,
          'size' => CRM_Utils_Type::HUGE,
          'import' => TRUE,
          'where' => 'civicrm_osdi_flag.status',
          'export' => TRUE,
          'default' => NULL,
          'table_name' => 'civicrm_osdi_flag',
          'entity' => 'Flag',
          'bao' => 'CRM_OSDI_DAO_Flag',
          'localizable' => 0,
          'html' => [
            'type' => 'Radio',
          ],
          'pseudoconstant' => [
            'callback' => 'CRM_OSDI_BAO_Flag::statusPseudoConstant',
            'prefetch' => 'true',
          ],
          'add' => NULL,
        ],
        'message' => [
          'name' => 'message',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => E::ts('Message'),
          'description' => E::ts('Description of the issue'),
          'maxlength' => 511,
          'size' => CRM_Utils_Type::HUGE,
          'import' => TRUE,
          'where' => 'civicrm_osdi_flag.message',
          'export' => TRUE,
          'default' => NULL,
          'table_name' => 'civicrm_osdi_flag',
          'entity' => 'Flag',
          'bao' => 'CRM_OSDI_DAO_Flag',
          'localizable' => 0,
          'html' => [
            'type' => 'TextArea',
          ],
          'add' => NULL,
        ],
        'context' => [
          'name' => 'context',
          'type' => CRM_Utils_Type::T_TEXT,
          'title' => E::ts('Context'),
          'description' => E::ts('Structured data to help understand the issue'),
          'import' => TRUE,
          'where' => 'civicrm_osdi_flag.context',
          'export' => TRUE,
          'default' => NULL,
          'table_name' => 'civicrm_osdi_flag',
          'entity' => 'Flag',
          'bao' => 'CRM_OSDI_DAO_Flag',
          'localizable' => 0,
          'serialize' => self::SERIALIZE_JSON,
          'add' => NULL,
        ],
        'created_date' => [
          'name' => 'created_date',
          'type' => CRM_Utils_Type::T_TIMESTAMP,
          'title' => E::ts('Created Date'),
          'description' => E::ts('When the flag was created'),
          'where' => 'civicrm_osdi_flag.created_date',
          'default' => 'CURRENT_TIMESTAMP',
          'table_name' => 'civicrm_osdi_flag',
          'entity' => 'Flag',
          'bao' => 'CRM_OSDI_DAO_Flag',
          'localizable' => 0,
          'add' => NULL,
        ],
        'modified_date' => [
          'name' => 'modified_date',
          'type' => CRM_Utils_Type::T_TIMESTAMP,
          'title' => E::ts('Modified Date'),
          'description' => E::ts('When the client was created or modified.'),
          'where' => 'civicrm_osdi_flag.modified_date',
          'default' => 'CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
          'table_name' => 'civicrm_osdi_flag',
          'entity' => 'Flag',
          'bao' => 'CRM_OSDI_DAO_Flag',
          'localizable' => 0,
          'add' => NULL,
        ],
      ];
      CRM_Core_DAO_AllCoreTables::invoke(__CLASS__, 'fields_callback', Civi::$statics[__CLASS__]['fields']);
    }
    return Civi::$statics[__CLASS__]['fields'];
  }

  /**
   * Return a mapping from field-name to the corresponding key (as used in fields()).
   *
   * @return array
   *   Array(string $name => string $uniqueName).
   */
  public static function &fieldKeys() {
    if (!isset(Civi::$statics[__CLASS__]['fieldKeys'])) {
      Civi::$statics[__CLASS__]['fieldKeys'] = array_flip(CRM_Utils_Array::collect('name', self::fields()));
    }
    return Civi::$statics[__CLASS__]['fieldKeys'];
  }

  /**
   * Returns the names of this table
   *
   * @return string
   */
  public static function getTableName() {
    return self::$_tableName;
  }

  /**
   * Returns if this table needs to be logged
   *
   * @return bool
   */
  public function getLog() {
    return self::$_log;
  }

  /**
   * Returns the list of fields that can be imported
   *
   * @param bool $prefix
   *
   * @return array
   */
  public static function &import($prefix = FALSE) {
    $r = CRM_Core_DAO_AllCoreTables::getImports(__CLASS__, 'osdi_flag', $prefix, []);
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
    $r = CRM_Core_DAO_AllCoreTables::getExports(__CLASS__, 'osdi_flag', $prefix, []);
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
        'sig' => 'civicrm_osdi_flag::0::contact_id',
      ],
      'index_remote_object_id' => [
        'name' => 'index_remote_object_id',
        'field' => [
          0 => 'remote_object_id',
        ],
        'localizable' => FALSE,
        'sig' => 'civicrm_osdi_flag::0::remote_object_id',
      ],
      'index_flag_type' => [
        'name' => 'index_flag_type',
        'field' => [
          0 => 'flag_type',
        ],
        'localizable' => FALSE,
        'sig' => 'civicrm_osdi_flag::0::flag_type',
      ],
      'index_status' => [
        'name' => 'index_status',
        'field' => [
          0 => 'status',
        ],
        'localizable' => FALSE,
        'sig' => 'civicrm_osdi_flag::0::status',
      ],
    ];
    return ($localize && !empty($indices)) ? CRM_Core_DAO_AllCoreTables::multilingualize(__CLASS__, $indices) : $indices;
  }

}
