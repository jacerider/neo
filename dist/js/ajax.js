(function(t) {
  t.AjaxCommands && (t.AjaxCommands.prototype.ajaxRedirect = function(o, e, n) {
    const a = e.data || {};
    if (typeof a.url > "u")
      throw new Error(
        t.t("The ajax redirect does not have a url.")
      );
    t.ajax(a).execute();
  });
})(Drupal);
//# sourceMappingURL=ajax.js.map
