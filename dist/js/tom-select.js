(function(c, s) {
  const l = {
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
  c.behaviors.neoTomSelect = {
    attach: () => {
      s("neo.tom", "select.neo-select").forEach((t) => {
        if (t instanceof HTMLSelectElement) {
          const e = t.parentElement;
          e && (e.classList.add("neo-tom-wrapper"), e.classList.add("neo-tom-select-wrapper"));
          let n = {
            maxOptions: null,
            allowEmptyOption: r(t) !== null
          };
          t.multiple && (n = { ...n, plugins: {
            remove_button: {
              title: "Remove this item"
            }
          } }), new TomSelect(t, { ...n, ...l });
        }
      }), s("neo.tom", "input.neo-entity-autocomplete").forEach((t) => {
        const e = t.parentElement;
        e && e.classList.add("neo-tom-wrapper");
        var n = {
          valueField: "value",
          labelField: "value",
          searchField: "label",
          plugins: {
            remove_button: {
              title: "Remove this item"
            }
          },
          onItemAdd: function() {
            const o = this;
            o.setTextboxValue(""), o.refreshOptions();
          },
          load: function(o, a) {
            const p = t.dataset.autocompletePath + "?q=" + encodeURIComponent(o);
            fetch(p).then((i) => i.json()).then((i) => {
              a(i);
            }).catch(() => {
              a();
            });
          }
        };
        new TomSelect(t, { ...n, ...l });
      });
    }
  };
})(Drupal, once);
//# sourceMappingURL=tom-select.js.map
