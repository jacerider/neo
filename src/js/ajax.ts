(function (Drupal) {

  if (Drupal.AjaxCommands) {

    Drupal.AjaxCommands.prototype.ajaxRedirect = function (_ajax, response, _status) {
      const options = response.data || {};
      if (typeof options.url === 'undefined') {
        throw new Error(
          Drupal.t('The ajax redirect does not have a url.'),
        );
      }
      Drupal.ajax(options).execute();
    } as drupal.Core.IAjaxCommand;
  }

})(Drupal);

export {};
