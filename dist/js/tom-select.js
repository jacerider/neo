(function(i, c, d) {
  const l = {
    dropdownParent: document.body,
    onInitialize: function() {
      const t = this, o = t.input, e = t.control;
      if (o.classList.contains("use-neo-tooltip") && i.behaviors.neoTooltip) {
        e.classList.add("use-neo-tooltip");
        for (const n in o.dataset)
          n.startsWith("tippy") && (e.dataset[n] = o.dataset[n]);
        i.behaviors.neoTooltip.attach(t.wrapper);
      }
      t.dropdownWatch = null, t.dropdownWatchCb = () => {
        if (t.isOpen) {
          t.popper.update();
          const n = t.wrapper.getBoundingClientRect();
          t.dropdown.style.width = n.width + "px";
        }
      }, t.popper = d.createPopper(t.wrapper, t.dropdown, {
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
  function u(t) {
    const o = t.options;
    for (let e = 0; e < o.length; e++) {
      const n = o[e];
      if (n.value === "")
        return n;
    }
    return null;
  }
  i.behaviors.neoTomSelect = {
    attach: () => {
      c("neo.tom", "select.neo-select").forEach((t) => {
        if (t instanceof HTMLSelectElement) {
          const o = t.parentElement;
          o && (o.classList.add("neo-tom-wrapper"), o.classList.add("neo-tom-select-wrapper"));
          let e = {
            allowEmptyOption: u(t) !== null
          };
          t.multiple && (e = { ...e, maxOptions: null, plugins: {
            remove_button: {
              title: "Remove this item"
            }
          } }), new TomSelect(t, { ...e, ...l });
        }
      }), c("neo.tom", "input.neo-entity-autocomplete").forEach((t) => {
        const o = t.parentElement, e = t.classList.contains("neo-multi-select");
        o && o.classList.add("neo-tom-wrapper");
        let n = {
          valueField: "value",
          labelField: "value",
          searchField: "label",
          dropdownParent: document.body,
          maxItems: 1,
          load: function(s, p) {
            const r = t.dataset.autocompletePath, m = r + (r.includes("?") ? "&" : "?") + "q=" + encodeURIComponent(s);
            fetch(m).then((a) => a.json()).then((a) => {
              p(a);
            }).catch(() => {
              p();
            });
          }
        };
        e && (n = { ...n, maxItems: null, onItemAdd: function() {
          const s = this;
          s.setTextboxValue(""), s.refreshOptions();
        }, plugins: {
          remove_button: {
            title: "Remove this item"
          }
        } }), new TomSelect(t, { ...n, ...l });
      });
    }
  };
})(Drupal, once, Popper);
//# sourceMappingURL=tom-select.js.map
