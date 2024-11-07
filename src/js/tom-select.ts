(function (Drupal, once) {

  Drupal.behaviors.neoTomSelect = {
    attach: () => {
      once('neo.coloris', '.neo-multi-select').forEach(el => {
        console.log(el);
        const parent = el.parentElement;
        if (parent) {
          parent.classList.add('neo-multi-select-wrapper');
        }

        var settings = {
          plugins: {
            remove_button: {
              title:'Remove this item',
            }
          },
        };
        new TomSelect(el, settings);

      });
    }
  };

})(Drupal, once);

export {};
