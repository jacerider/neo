(function (Drupal, once) {

  Drupal.behaviors.neoTomSelect = {
    attach: () => {
      once('neo.tom', 'select.neo-multi-select').forEach(el => {
        const parent = el.parentElement;
        if (parent) {
          parent.classList.add('neo-multi-select-wrapper');
        }
        var settings = {
          maxOptions: null,
          plugins: {
            remove_button: {
              title:'Remove this item',
            }
          }
        };
        new TomSelect(el, settings);
      });
    }
  };

})(Drupal, once);

export {};
