(function(i, a) {
  const c = {
    onInitialize: function() {
      const t = this, e = t.input, o = t.control;
      if (e.classList.contains("use-neo-tooltip") && i.behaviors.neoTooltip) {
        o.classList.add("use-neo-tooltip");
        for (const n in e.dataset)
          n.startsWith("tippy") && (o.dataset[n] = e.dataset[n]);
        i.behaviors.neoTooltip.attach(t.wrapper);
      }
    },
    onFocus: function() {
      i.behaviors.neoTooltip && i.behaviors.neoTooltip.disableAll();
    },
    onBlur: function() {
      i.behaviors.neoTooltip && i.behaviors.neoTooltip.enableAll();
    },
    render: {
      dropdown: function() {
        return '<div class="neo-tom-dropdown"></div>';
      }
    }
  };
  function m(t) {
    const e = t.options;
    for (let o = 0; o < e.length; o++) {
      const n = e[o];
      if (n.value === "")
        return n;
    }
    return null;
  }
  i.behaviors.neoTomSelect = {
    attach: () => {
      a("neo.tom", "select.neo-select").forEach((t) => {
        if (t instanceof HTMLSelectElement) {
          const e = t.parentElement;
          e && (e.classList.add("neo-tom-wrapper"), e.classList.add("neo-tom-select-wrapper"));
          let o = {
            allowEmptyOption: m(t) !== null
          };
          t.multiple && (o = { ...o, maxOptions: null, plugins: {
            remove_button: {
              title: "Remove this item"
            }
          } }), new TomSelect(t, { ...o, ...c });
        }
      }), a("neo.tom", "input.neo-entity-autocomplete").forEach((t) => {
        const e = t.parentElement, o = t.classList.contains("neo-multi-select");
        e && e.classList.add("neo-tom-wrapper");
        let n = {
          valueField: "value",
          labelField: "value",
          searchField: "label",
          maxItems: 1,
          load: function(s, p) {
            const r = t.dataset.autocompletePath, u = r + (r.includes("?") ? "&" : "?") + "q=" + encodeURIComponent(s);
            fetch(u).then((l) => l.json()).then((l) => {
              p(l);
            }).catch(() => {
              p();
            });
          }
        };
        o && (n = { ...n, maxItems: null, onItemAdd: function() {
          const s = this;
          s.setTextboxValue(""), s.refreshOptions();
        }, plugins: {
          remove_button: {
            title: "Remove this item"
          }
        } }), new TomSelect(t, { ...n, ...c });
      });
    }
  };
})(Drupal, once);
//# sourceMappingURL=tom-select.js.map
