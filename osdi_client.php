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
  Civi::dispatcher()->addListener('&hook_civicrm_alterLocationMergeData', ['\Civi\Osdi\CrmEventDispatch', 'alterLocationMergeData']);
  Civi::dispatcher()->addListener('&hook_civicrm_merge', ['\Civi\Osdi\CrmEventDispatch', 'merge']);
  Civi::dispatcher()->addListener('&hook_civicrm_pre', ['\Civi\Osdi\CrmEventDispatch', 'pre']);
  Civi::dispatcher()->addListener('&hook_civicrm_post', ['\Civi\Osdi\CrmEventDispatch', 'post']);
  Civi::dispatcher()->addListener('&hook_civicrm_postCommit', ['\Civi\Osdi\CrmEventDispatch', 'postCommit']);
  Civi::dispatcher()->addListener('&hook_civicrm_queueRun_osdiclient', ['\Civi\Osdi\Queue', 'runQueue']);

  _osdi_client_civix_civicrm_config($config);
}

function osdi_client_civicrm_check(&$messages, $statusNames, $includeDisabled) {
  if ($statusNames && !in_array('osdiClientZombieJob', $statusNames)) {
    return;
  }

  if (!$includeDisabled) {
    $disabled = \Civi\Api4\StatusPreference::get()
      ->setCheckPermissions(FALSE)
      ->addWhere('is_active', '=', FALSE)
      ->addWhere('domain_id', '=', 'current_domain')
      ->addWhere('name', '=', 'osdiClientZombieJob')
      ->execute()->count();
    if ($disabled) {
      return;
    }
  }

  $jobStartTime = Civi::settings()->get('osdiClient.syncJobStartTime');
  $jobProcessId = Civi::settings()->get('osdiClient.syncJobProcessId');
  if (empty($jobStartTime) || empty(($jobProcessId))) {
    return;
  }

  if (posix_getsid($jobProcessId) === FALSE) {
    return;
  }

  if (time() - $jobStartTime > 3600) {
    $messages[] = new CRM_Utils_Check_Message(
      'osdiClientZombieJob',
      ts('An Action Network sync job has been running for over an hour. '
        . 'This prevents new sync jobs from running. Process ID %1 began %2.',
        [1 => $jobProcessId, 2 => date(DATE_COOKIE, $jobStartTime)]),
      ts('Long-Running Action Network Sync'),
      \Psr\Log\LogLevel::WARNING,
      'fa-hourglass'
    );
  }
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
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function osdi_client_civicrm_entityTypes(&$entityTypes) {
  _osdi_client_civix_civicrm_entityTypes($entityTypes);
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
