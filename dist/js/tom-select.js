(function(o, n) {
  o.behaviors.neoTomSelect = {
    attach: () => {
      n("neo.tom", "select.neo-multi-select").forEach((e) => {
        const t = e.parentElement;
        t && t.classList.add("neo-multi-select-wrapper"), e instanceof HTMLSelectElement && (console.log("EL", e, e.value), e.value = e.value || "");
        var c = {
          plugins: {
            remove_button: {
              title: "Remove this item"
            }
          },
          onItemAdd: function() {
            console.log("THIS", this);
          }
        };
        new TomSelect(e, c).removeOption("_none");
      });
    }
  };
})(Drupal, once);
//# sourceMappingURL=tom-select.js.map
