(function ($, Drupal) {

  Drupal.behaviors.neoVisibility = {
    attach: () => {
      // The drupalSetSummary method required for this behavior is not available
      // on the Blocks administration page, so we need to make sure this
      // behavior is processed only if drupalSetSummary is defined.
      if (typeof $.fn.drupalSetSummary === 'undefined') {
        return;
      }

      /**
       * Create a summary for checkboxes in the provided context.
       *
       * @param {Document|HTMLElement} context
       *   A context where one would find checkboxes to summarize.
       *
       * @return {string}
       *   A string with the summary.
       */
      function checkboxesSummary(context:any) {
        const values = [];
        const $checkboxes = $(context).find(
          'input[type="checkbox"]:checked + label',
        );
        const il = $checkboxes.length;
        for (let i = 0; i < il; i++) {
          values.push($($checkboxes[i]).html());
        }
        if (!values.length) {
          values.push(Drupal.t('Not restricted'));
        }
        return values.join(', ');
      }

      $(
        '[data-drupal-selector="edit-visibility-node-type"], [data-drupal-selector="edit-visibility-language"], [data-drupal-selector="edit-visibility-user-role"], [data-drupal-selector="edit-visibility-response-status"], [data-drupal-selector^="edit-visibility-entity-bundle"]',
      ).drupalSetSummary(checkboxesSummary);

      $(
        '[data-drupal-selector="edit-visibility-request-path"]',
      ).drupalSetSummary((context:any) => {
        const $pages = $(context).find(
          'textarea[name="visibility[request_path][pages]"]',
        ) as any;
        if (!$pages.length || !$pages[0].value) {
          return Drupal.t('Not restricted');
        }
        return Drupal.t('Restricted to certain pages');
      });
    }
  };

})(jQuery, Drupal);

export {};
