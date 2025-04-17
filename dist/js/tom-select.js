(function(i, l, u) {
  const c = {
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
      }, t.popper = u.createPopper(t.wrapper, t.dropdown, {
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
  function h(t) {
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
      l("neo.tom", "select.neo-select").forEach((t) => {
        if (t instanceof HTMLSelectElement) {
          const o = t.parentElement;
          o && (o.classList.add("neo-tom-wrapper"), o.classList.add("neo-tom-select-wrapper"));
          let n = {
            allowEmptyOption: h(t) !== null
          };
          t.multiple && (n = { ...n, maxOptions: null, plugins: {
            remove_button: {
              title: "Remove this item"
            }
          } }), new TomSelect(t, { ...n, ...c });
        }
      }), l("neo.tom", "input.neo-entity-autocomplete").forEach((t) => {
        const o = t.parentElement, n = t.classList.contains("neo-multi-select");
        o && o.classList.add("neo-tom-wrapper");
        let e = {
          valueField: "value",
          labelField: "value",
          searchField: "label",
          create: !0,
          dropdownParent: document.body,
          maxItems: 1,
          load: function(s, p) {
            const d = t.dataset.autocompletePath, m = d + (d.includes("?") ? "&" : "?") + "q=" + encodeURIComponent(s);
            fetch(m).then((a) => a.json()).then((a) => {
              p(a);
            }).catch(() => {
              p();
            });
          }
        };
        const r = t.dataset.autocompleteFirstCharacterBlacklist || !1;
        r && (e.shouldLoad = function(s) {
          return !(s.length > 0 && r.includes(s[0]));
        }), n && (e = { ...e, maxItems: null, onItemAdd: function() {
          const s = this;
          s.setTextboxValue(""), s.refreshOptions();
        }, plugins: {
          remove_button: {
            title: "Remove this item"
          }
        } }), new TomSelect(t, { ...e, ...c });
      });
    }
  };
})(Drupal, once, Popper);
//# sourceMappingURL=tom-select.js.map
