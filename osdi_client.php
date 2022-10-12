<?php

require_once 'osdi_client.civix.php';
// phpcs:disable
use CRM_OSDI_ExtensionUtil as E;
// phpcs:enable

require_once __DIR__ . DIRECTORY_SEPARATOR
    . 'vendor' . DIRECTORY_SEPARATOR
    . 'hal-client' . DIRECTORY_SEPARATOR
    . 'vendor' . DIRECTORY_SEPARATOR
    . 'autoload.php';

/**
 * Implements hook_civicrm_searchKitTasks().
 */
function osdi_client_civicrm_searchKitTasks(array &$tasks, bool $checkPermissions, ?int $userId) {
  $tasks['Contact']['osdi-sync'] = [
    'module' => 'osdiSearchTasks',
    'title' => E::ts('Upload to Action Network'),
    'icon' => 'fa-cloud-upload',
    'uiDialog' => ['templateUrl' => '~/osdiSearchTasks/osdiSearchTaskSync.html'],
  ];
}

function osdi_client_civicrm_config(&$config) {
  if (isset(Civi::$statics[__FUNCTION__])) {
    return;
  }
  Civi::$statics[__FUNCTION__] = 1;

  Civi::dispatcher()->addListener('civi.dao.preDelete', ['\Civi\Osdi\CrmEventDispatch', 'daoPreDelete']);
  Civi::dispatcher()->addListener('civi.dao.preUpdate', ['\Civi\Osdi\CrmEventDispatch', 'daoPreUpdate']);
  Civi::dispatcher()->addListener('&hook_civicrm_merge', ['\Civi\Osdi\CrmEventDispatch', 'merge']);
  Civi::dispatcher()->addListener('&hook_civicrm_pre', ['\Civi\Osdi\CrmEventDispatch', 'pre']);
  Civi::dispatcher()->addListener('&hook_civicrm_postCommit', ['\Civi\Osdi\CrmEventDispatch', 'postCommit']);

  _osdi_client_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_xmlMenu
 */
function osdi_client_civicrm_xmlMenu(&$files) {
  _osdi_client_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function osdi_client_civicrm_install() {
  _osdi_client_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
 */
function osdi_client_civicrm_postInstall() {
  _osdi_client_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
 */
function osdi_client_civicrm_uninstall() {
  _osdi_client_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function osdi_client_civicrm_enable() {
  _osdi_client_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_disable
 */
function osdi_client_civicrm_disable() {
  _osdi_client_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_upgrade
 */
function osdi_client_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _osdi_client_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
 */
function osdi_client_civicrm_managed(&$entities) {
  _osdi_client_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_caseTypes
 */
function osdi_client_civicrm_caseTypes(&$caseTypes) {
  _osdi_client_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
 */
function osdi_client_civicrm_angularModules(&$angularModules) {
  _osdi_client_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsFolders
 */
function osdi_client_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _osdi_client_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function osdi_client_civicrm_entityTypes(&$entityTypes) {
  _osdi_client_civix_civicrm_entityTypes($entityTypes);
}

/**
 * Implements hook_civicrm_thems().
 */
function osdi_client_civicrm_themes(&$themes) {
  _osdi_client_civix_civicrm_themes($themes);
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_preProcess
 */
//function osdi_client_civicrm_preProcess($formName, &$form) {
//
//}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */
//function osdi_client_civicrm_navigationMenu(&$menu) {
//  _osdi_client_civix_insert_navigation_menu($menu, 'Mailings', array(
//    'label' => E::ts('New subliminal message'),
//    'name' => 'mailing_subliminal_message',
//    'url' => 'civicrm/mailing/subliminal',
//    'permission' => 'access CiviMail',
//    'operator' => 'OR',
//    'separator' => 0,
//  ));
//  _osdi_client_civix_navigationMenu($menu);
//}
