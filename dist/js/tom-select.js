(function(o, n) {
  o.behaviors.neoTomSelect = {
    attach: () => {
      n("neo.coloris", ".neo-multi-select").forEach((e) => {
        console.log(e);
        const t = e.parentElement;
        t && t.classList.add("neo-multi-select-wrapper");
        var s = {
          plugins: {
            remove_button: {
              title: "Remove this item"
            }
          }
        };
        new TomSelect(e, s);
      });
    }
  };
})(Drupal, once);
//# sourceMappingURL=tom-select.js.map
