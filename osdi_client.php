<?php

require_once 'osdi_client.civix.php';
// phpcs:disable
use Civi\Osdi\Logger;
use CRM_OSDI_ExtensionUtil as E;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
// phpcs:enable

require_once __DIR__ . DIRECTORY_SEPARATOR
    . 'vendor' . DIRECTORY_SEPARATOR
    . 'autoload.php';

function osdi_client_civicrm_container(ContainerBuilder $container) {
  $container->setDefinition('log.osdi', new Definition(Logger::class, []))
    ->setPublic(TRUE);
}

function osdi_client_civicrm_config(&$config) {
  if (isset(Civi::$statics[__FUNCTION__])) {
    return;
  }
  Civi::$statics[__FUNCTION__] = 1;

  Civi::dispatcher()->addListener('civi.api.exception', function (\Civi\API\Event\ExceptionEvent $e) {
    $signature = $e->getEntityName() . ':' . $e->getActionName();
    if (str_contains(strtolower($signature), 'osdi')) {
      Logger::logError('API Exception', ['exception' => $e->getException()]);
    }
  });

  Civi::dispatcher()->addListener('civi.api.respond', function (\Civi\API\Event\RespondEvent $e) {
    $signature = $e->getEntityName() . ':' . $e->getActionName();
    if (str_contains(strtolower($signature), 'osdi')) {
      $response = $e->getResponse();
      if (is_array($response && ($response['is_error'] ?? FALSE))) {
        Logger::logError('API Error', $response);
      }
    }
  });

  Civi::dispatcher()->addListener('&hook_civicrm_post::OsdiSyncProfile', ['\Civi\OsdiClient', 'postSaveOsdiSyncProfile']);
  Civi::dispatcher()->addListener('&hook_civicrm_queueRun_osdiclient', ['\Civi\Osdi\Queue', 'runQueue']);

  osdi_client_add_syncprofile_dependent_listeners();

  _osdi_client_civix_civicrm_config($config);
}

function osdi_client_add_syncprofile_dependent_listeners(): void {
  // only run this function once per Civi container build
  if (Civi::dispatcher()->hasListeners(__FUNCTION__)) {
    return;
  }

  // don't bother if we don't have/can't make an OsdiClient container
  if (empty(Civi::settings()->get('osdiClient.defaultSyncProfile'))) {
    if (!\Civi\OsdiClient::containerIsInitialized()) {
      return;
    }
  }

  // the dummy listener helps us only add the other listeners once
  Civi::dispatcher()->addListener(__FUNCTION__, 'dummy');
  Civi::dispatcher()->addListener('civi.dao.preDelete', ['\Civi\Osdi\CrmEventDispatch', 'daoPreDelete']);
  Civi::dispatcher()->addListener('civi.dao.preUpdate', ['\Civi\Osdi\CrmEventDispatch', 'daoPreUpdate']);
  Civi::dispatcher()->addListener('&hook_civicrm_alterLocationMergeData', ['\Civi\Osdi\CrmEventDispatch', 'alterLocationMergeData']);
  Civi::dispatcher()->addListener('&hook_civicrm_merge', ['\Civi\Osdi\CrmEventDispatch', 'merge']);
  Civi::dispatcher()->addListener('&hook_civicrm_pre', ['\Civi\Osdi\CrmEventDispatch', 'pre']);
  Civi::dispatcher()->addListener('&hook_civicrm_post', ['\Civi\Osdi\CrmEventDispatch', 'post']);
  Civi::dispatcher()->addListener('&hook_civicrm_postCommit', ['\Civi\Osdi\CrmEventDispatch', 'postCommit']);
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

  if (time() - strtotime($jobStartTime) > 3600) {
    $messages[] = new CRM_Utils_Check_Message(
      'osdiClientZombieJob',
      ts('An Action Network sync job has been running for over an hour. '
        . 'This prevents new sync jobs from running. Process ID %1 began %2.',
        [1 => $jobProcessId, 2 => $jobStartTime]),
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
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function osdi_client_civicrm_enable() {
  _osdi_client_civix_civicrm_enable();
}

// --- Functions below this ship commented out. Uncomment as required. ---

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
