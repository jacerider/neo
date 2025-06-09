(function(s, u, w) {
  const f = {
    dropdownParent: document.body,
    maxOptions: null,
    onInitialize: function() {
      const t = this, o = t.input, n = t.control;
      if (o.classList.contains("use-neo-tooltip") && s.behaviors.neoTooltip) {
        n.classList.add("use-neo-tooltip");
        for (const e in o.dataset)
          e.startsWith("tippy") && (n.dataset[e] = o.dataset[e]);
        s.behaviors.neoTooltip.attach(t.wrapper);
      }
      t.dropdownWatch = null, t.dropdownWatchCb = () => {
        if (t.isOpen) {
          t.popper.update();
          const e = t.wrapper.getBoundingClientRect();
          t.dropdown.style.width = Math.max(e.width, 140) + "px";
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
      s.behaviors.neoTooltip && s.behaviors.neoTooltip.disableAll();
    },
    onBlur: function() {
      s.behaviors.neoTooltip && s.behaviors.neoTooltip.enableAll();
    },
    render: {
      dropdown: function() {
        return '<div class="neo-tom-dropdown"></div>';
      }
    }
  };
  function b(t) {
    const o = t.options;
    for (let n = 0; n < o.length; n++) {
      const e = o[n];
      if (e.value === "")
        return e;
    }
    return null;
  }
  s.behaviors.neoTomSelect = {
    attach: () => {
      u("neo.tom", "select.neo-select").forEach((t) => {
        if (t instanceof HTMLSelectElement) {
          var o = new IntersectionObserver((n, e) => {
            n.forEach((p) => {
              if (p.intersectionRatio > 0) {
                e.disconnect();
                const r = t.closest(".form--item");
                r && (r.classList.add("neo-tom-wrapper"), r.classList.add("neo-tom-select-wrapper"));
                let c = {
                  allowEmptyOption: b(t) !== null,
                  selectOnTab: !0,
                  plugins: {
                    dropdown_input: {}
                  },
                  placeholder: "Search..."
                };
                t.multiple && (c = { ...c, maxOptions: null, plugins: {
                  remove_button: {
                    title: "Remove this item"
                  }
                } });
                const a = new TomSelect(t, { ...c, ...f });
                t.multiple && a.removeOption("_none");
              }
            });
          });
          o.observe(t);
        }
      }), u("neo.tom", "input.neo-entity-autocomplete").forEach((t) => {
        var o = new IntersectionObserver((n, e) => {
          n.forEach((p) => {
            if (p.intersectionRatio > 0) {
              e.disconnect();
              const r = t.parentElement, c = t.classList.contains("neo-multi-select");
              r && r.classList.add("neo-tom-wrapper");
              let a = {
                valueField: "value",
                labelField: "value",
                searchField: "label",
                create: !0,
                createOnBlur: !0,
                dropdownParent: document.body,
                maxItems: 1,
                load: function(i, l) {
                  const v = t.dataset.autocompletePath, g = v + (v.includes("?") ? "&" : "?") + "q=" + encodeURIComponent(i);
                  fetch(g).then((d) => d.json()).then((d) => {
                    l(d);
                  }).catch(() => {
                    l();
                  });
                }
              };
              const h = t.dataset.autocompleteFirstCharacterBlacklist || !1;
              h && (a.shouldLoad = function(i) {
                return !(i.length > 0 && h.includes(i[0]));
              }), c && (a = { ...a, maxItems: null, onItemAdd: function() {
                const i = this;
                i.setTextboxValue(""), i.refreshOptions();
              }, plugins: {
                remove_button: {
                  title: "Remove this item"
                }
              } });
              const m = { ...a, ...f };
              m.render.option_create = function(i, l) {
                return t.classList.contains("neo-autocreate") ? '<div class="create">Create <strong>' + l(i.input) + "</strong>&hellip;</div>" : '<div class="create">Use <strong>' + l(i.input) + "</strong></div>";
              }, new TomSelect(t, m);
            }
          });
        });
        o.observe(t);
      });
    }
  };
})(Drupal, once, Popper);
//# sourceMappingURL=tom-select.js.map
