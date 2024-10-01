(function(e, i) {
  i.behaviors.neoVisibility = {
    attach: () => {
      if (typeof e.fn.drupalSetSummary > "u")
        return;
      function l(a) {
        const t = [], s = e(a).find(
          'input[type="checkbox"]:checked + label'
        ), d = s.length;
        for (let r = 0; r < d; r++)
          t.push(e(s[r]).html());
        return t.length || t.push(i.t("Not restricted")), t.join(", ");
      }
      e(
        '[data-drupal-selector="edit-visibility-node-type"], [data-drupal-selector="edit-visibility-language"], [data-drupal-selector="edit-visibility-user-role"], [data-drupal-selector="edit-visibility-response-status"], [data-drupal-selector^="edit-visibility-entity-bundle"]'
      ).drupalSetSummary(l), e(
        '[data-drupal-selector="edit-visibility-request-path"]'
      ).drupalSetSummary((a) => {
        const t = e(a).find(
          'textarea[name="visibility[request_path][pages]"]'
        );
        return !t.length || !t[0].value ? i.t("Not restricted") : i.t("Restricted to certain pages");
      });
    }
  };
})(jQuery, Drupal);
//# sourceMappingURL=visibility.js.map
