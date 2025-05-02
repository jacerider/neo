(function(i, d, v) {
  const u = {
    dropdownParent: document.body,
    maxOptions: null,
    onInitialize: function() {
      const t = this, e = t.input, n = t.control;
      if (e.classList.contains("use-neo-tooltip") && i.behaviors.neoTooltip) {
        n.classList.add("use-neo-tooltip");
        for (const o in e.dataset)
          o.startsWith("tippy") && (n.dataset[o] = e.dataset[o]);
        i.behaviors.neoTooltip.attach(t.wrapper);
      }
      t.dropdownWatch = null, t.dropdownWatchCb = () => {
        if (t.isOpen) {
          t.popper.update();
          const o = t.wrapper.getBoundingClientRect();
          t.dropdown.style.width = o.width + "px";
        }
      }, t.popper = v.createPopper(t.wrapper, t.dropdown, {
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
  function w(t) {
    const e = t.options;
    for (let n = 0; n < e.length; n++) {
      const o = e[n];
      if (o.value === "")
        return o;
    }
    return null;
  }
  i.behaviors.neoTomSelect = {
    attach: () => {
      d("neo.tom", "select.neo-select").forEach((t) => {
        if (t instanceof HTMLSelectElement) {
          var e = new IntersectionObserver((n, o) => {
            n.forEach((l) => {
              if (l.intersectionRatio > 0) {
                o.disconnect();
                const a = t.closest(".form--item");
                a && (a.classList.add("neo-tom-wrapper"), a.classList.add("neo-tom-select-wrapper"));
                let r = {
                  allowEmptyOption: w(t) !== null
                };
                t.multiple && (r = { ...r, maxOptions: null, plugins: {
                  remove_button: {
                    title: "Remove this item"
                  }
                } });
                const c = new TomSelect(t, { ...r, ...u });
                t.multiple && c.removeOption("_none");
              }
            });
          });
          e.observe(t);
        }
      }), d("neo.tom", "input.neo-entity-autocomplete").forEach((t) => {
        var e = new IntersectionObserver((n, o) => {
          n.forEach((l) => {
            if (l.intersectionRatio > 0) {
              o.disconnect();
              const a = t.parentElement, r = t.classList.contains("neo-multi-select");
              a && a.classList.add("neo-tom-wrapper");
              let c = {
                valueField: "value",
                labelField: "value",
                searchField: "label",
                create: t.classList.contains("neo-autocreate"),
                dropdownParent: document.body,
                maxItems: 1,
                load: function(s, m) {
                  const h = t.dataset.autocompletePath, b = h + (h.includes("?") ? "&" : "?") + "q=" + encodeURIComponent(s);
                  fetch(b).then((p) => p.json()).then((p) => {
                    m(p);
                  }).catch(() => {
                    m();
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
        e.observe(t);
      });
    }
  };
})(Drupal, once, Popper);
//# sourceMappingURL=tom-select.js.map
