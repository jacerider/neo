(function(p, i) {
  const a = {
    // dropdownParent: 'form',
    render: {
      dropdown: function() {
        return '<div class="neo-tom-dropdown"></div>';
      }
    }
  };
  function r(t) {
    const e = t.options;
    for (let n = 0; n < e.length; n++) {
      const o = e[n];
      if (o.value === "")
        return o;
    }
    return null;
  }
  p.behaviors.neoTomSelect = {
    attach: () => {
      i("neo.tom", "select.neo-select").forEach((t) => {
        if (t instanceof HTMLSelectElement) {
          const e = t.parentElement;
          e && (e.classList.add("neo-tom-wrapper"), e.classList.add("neo-tom-select-wrapper"));
          let n = {
            allowEmptyOption: r(t) !== null
          };
          t.multiple && (n = { ...n, maxOptions: null, plugins: {
            remove_button: {
              title: "Remove this item"
            }
          } }), new TomSelect(t, { ...n, ...a });
        }
      }), i("neo.tom", "input.neo-entity-autocomplete").forEach((t) => {
        const e = t.parentElement, n = t.classList.contains("neo-multi-select");
        e && e.classList.add("neo-tom-wrapper");
        let o = {
          valueField: "value",
          labelField: "value",
          searchField: "label",
          maxItems: 1,
          load: function(s, c) {
            const m = t.dataset.autocompletePath, u = m + (m.includes("?") ? "&" : "?") + "q=" + encodeURIComponent(s);
            fetch(u).then((l) => l.json()).then((l) => {
              c(l);
            }).catch(() => {
              c();
            });
          }
        };
        n && (o = { ...o, maxItems: null, onItemAdd: function() {
          const s = this;
          s.setTextboxValue(""), s.refreshOptions();
        }, plugins: {
          remove_button: {
            title: "Remove this item"
          }
        } }, console.log(o)), new TomSelect(t, { ...o, ...a });
      });
    }
  };
})(Drupal, once);
//# sourceMappingURL=tom-select.js.map
