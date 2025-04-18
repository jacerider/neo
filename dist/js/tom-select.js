(function(i, d, w) {
  const u = {
    dropdownParent: document.body,
    maxOptions: null,
    onInitialize: function() {
      const t = this, o = t.input, n = t.control;
      if (o.classList.contains("use-neo-tooltip") && i.behaviors.neoTooltip) {
        n.classList.add("use-neo-tooltip");
        for (const e in o.dataset)
          e.startsWith("tippy") && (n.dataset[e] = o.dataset[e]);
        i.behaviors.neoTooltip.attach(t.wrapper);
      }
      t.dropdownWatch = null, t.dropdownWatchCb = () => {
        if (t.isOpen) {
          t.popper.update();
          const e = t.wrapper.getBoundingClientRect();
          t.dropdown.style.width = e.width + "px";
        }
      }, t.popper = w.createPopper(t.wrapper, t.dropdown, {
        modifiers: [
          {
            name: "preventOverflow",
            options: {
              boundary: t.wrapper
            }
          }
        ]
      });
    },
    onDropdownOpen: function() {
      const t = this;
      t.dropdownWatchCb(), t.dropdownWatch = setInterval(t.dropdownWatchCb, 250);
    },
    onDropdownClose: function() {
      clearInterval(this.dropdownWatch);
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
  function v(t) {
    const o = t.options;
    for (let n = 0; n < o.length; n++) {
      const e = o[n];
      if (e.value === "")
        return e;
    }
    return null;
  }
  i.behaviors.neoTomSelect = {
    attach: () => {
      d("neo.tom", "select.neo-select").forEach((t) => {
        if (t instanceof HTMLSelectElement) {
          var o = new IntersectionObserver((n, e) => {
            n.forEach((l) => {
              if (l.intersectionRatio > 0) {
                e.disconnect();
                const a = t.parentElement;
                a && (a.classList.add("neo-tom-wrapper"), a.classList.add("neo-tom-select-wrapper"));
                let r = {
                  allowEmptyOption: v(t) !== null
                };
                t.multiple && (r = { ...r, maxOptions: null, plugins: {
                  remove_button: {
                    title: "Remove this item"
                  }
                } }), new TomSelect(t, { ...r, ...u });
              }
            });
          });
          o.observe(t);
        }
      }), d("neo.tom", "input.neo-entity-autocomplete").forEach((t) => {
        var o = new IntersectionObserver((n, e) => {
          n.forEach((l) => {
            if (l.intersectionRatio > 0) {
              e.disconnect();
              const a = t.parentElement, r = t.classList.contains("neo-multi-select");
              a && a.classList.add("neo-tom-wrapper");
              let c = {
                valueField: "value",
                labelField: "value",
                searchField: "label",
                create: t.classList.contains("neo-autocreate"),
                dropdownParent: document.body,
                maxItems: 1,
                load: function(s, h) {
                  const m = t.dataset.autocompletePath, b = m + (m.includes("?") ? "&" : "?") + "q=" + encodeURIComponent(s);
                  fetch(b).then((p) => p.json()).then((p) => {
                    h(p);
                  }).catch(() => {
                    h();
                  });
                }
              };
              const f = t.dataset.autocompleteFirstCharacterBlacklist || !1;
              f && (c.shouldLoad = function(s) {
                return !(s.length > 0 && f.includes(s[0]));
              }), r && (c = { ...c, maxItems: null, onItemAdd: function() {
                const s = this;
                s.setTextboxValue(""), s.refreshOptions();
              }, plugins: {
                remove_button: {
                  title: "Remove this item"
                }
              } }), new TomSelect(t, { ...c, ...u });
            }
          });
        });
        o.observe(t);
      });
    }
  };
})(Drupal, once, Popper);
//# sourceMappingURL=tom-select.js.map
