(function(n, o) {
  n.behaviors.neoTomSelect = {
    attach: () => {
      o("neo.tom", "select.neo-multi-select").forEach((e) => {
        const t = e.parentElement;
        t && t.classList.add("neo-multi-select-wrapper");
        var l = {
          maxOptions: null,
          plugins: {
            remove_button: {
              title: "Remove this item"
            }
          }
        };
        new TomSelect(e, l);
      });
    }
  };
})(Drupal, once);
//# sourceMappingURL=tom-select.js.map
