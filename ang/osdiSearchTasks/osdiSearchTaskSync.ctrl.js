(function(angular, $, _) {
  "use strict";

  angular.module('osdiSearchTasks').controller('osdiSearchTaskSync', function($scope, dialogService) {
    var ts = $scope.ts = CRM.ts('osdi-client'),
      model = $scope.model,
      ctrl = this;

    this.cancel = function() {
      dialogService.cancel('crmSearchTask');
    };

    this.sync = function() {
      $('.ui-dialog-titlebar button').hide();
      ctrl.run = {
        select: ['id'],
        chain: {osdiSync: ['Contact', 'OsdiSync', {where: [['id', '=', '$id']]}]}
      };
    };

    this.onSuccess = function() {
      CRM.alert(ts('Processed %1 contacts.', {1: model.ids.length}), ts('Processed'), 'success');
      dialogService.close('crmSearchTask');
    };

    this.onError = function() {
      CRM.alert(ts('An error occurred while attempting to sync %1 contacts.', {1: model.ids.length}), ts('Error'), 'error');
      dialogService.close('crmSearchTask');
    };

  });
})(angular, CRM.$, CRM._);
