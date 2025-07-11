(function(a, m, w) {
  const v = {
    dropdownParent: document.body,
    maxOptions: null,
    onInitialize: function() {
      const t = this, o = t.input, i = t.control;
      if (o.classList.contains("use-neo-tooltip") && a.behaviors.neoTooltip) {
        i.classList.add("use-neo-tooltip");
        for (const n in o.dataset)
          n.startsWith("tippy") && (i.dataset[n] = o.dataset[n]);
        a.behaviors.neoTooltip.attach(t.wrapper);
      }
      t.dropdownWatch = null, t.dropdownWatchCb = () => {
        if (t.isOpen) {
          t.popper.update();
          const n = t.wrapper.getBoundingClientRect();
          t.dropdown.style.width = Math.max(n.width, 140) + "px";
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
      a.behaviors.neoTooltip && a.behaviors.neoTooltip.disableAll();
    },
    onBlur: function() {
      a.behaviors.neoTooltip && a.behaviors.neoTooltip.enableAll();
    },
    render: {
      dropdown: function() {
        return '<div class="neo-tom-dropdown"></div>';
      }
    }
  };
  function g(t) {
    const o = t.options;
    for (let i = 0; i < o.length; i++) {
      const n = o[i];
      if (n.value === "")
        return n;
    }
    return null;
  }
  a.behaviors.neoTomSelect = {
    attach: () => {
      m("neo.tom", "select.neo-select").forEach((t) => {
        if (t instanceof HTMLSelectElement) {
          var o = new IntersectionObserver((i, n) => {
            i.forEach((f) => {
              if (f.intersectionRatio > 0) {
                n.disconnect();
                const c = t.closest(".form--item");
                c && (c.classList.add("neo-tom-wrapper"), c.classList.add("neo-tom-select-wrapper"));
                let u = {
                  allowEmptyOption: g(t) !== null,
                  selectOnTab: !0,
                  plugins: {
                    dropdown_input: {}
                  },
                  placeholder: "Search..."
                };
                t.multiple && (u = { ...u, maxOptions: null, plugins: {
                  remove_button: {
                    title: "Remove this item"
                  }
                } });
                const l = new TomSelect(t, { ...u, ...v });
                t.multiple && l.removeOption("_none");
              }
            });
          });
          o.observe(t);
        }
      }), m("neo.tom", "input.neo-entity-autocomplete").forEach((t) => {
        var o = new IntersectionObserver((i, n) => {
          i.forEach((f) => {
            if (f.intersectionRatio > 0) {
              n.disconnect();
              const c = t.parentElement, u = t.classList.contains("neo-multi-select");
              c && c.classList.add("neo-tom-wrapper");
              let l = {
                valueField: "value",
                labelField: "label",
                searchField: "label",
                create: !0,
                createOnBlur: !0,
                dropdownParent: document.body,
                maxItems: 1,
                load: function(e, r) {
                  const d = t.dataset.autocompletePath, s = d + (d.includes("?") ? "&" : "?") + "q=" + encodeURIComponent(e);
                  fetch(s).then((h) => h.json()).then((h) => {
                    r(h);
                  }).catch(() => {
                    r();
                  });
                }
              };
              const b = t.dataset.autocompleteFirstCharacterBlacklist || !1;
              b && (l.shouldLoad = function(e) {
                return !(e.length > 0 && b.includes(e[0]));
              }), u && (l = { ...l, maxItems: null, onItemAdd: function() {
                const e = this;
                e.setTextboxValue(""), e.refreshOptions();
              }, plugins: {
                remove_button: {
                  title: "Remove this item"
                }
              } });
              const p = { ...l, ...v };
              p.render.item = function(e, r) {
                return "<div>" + r(e[p.valueField]) + "</div>";
              }, p.render.option = function(e, r) {
                let d = e[p.labelField] || "";
                if (e.option) {
                  d = e.option;
                  let s = d.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, "");
                  return s = s.replace(/\s*on\w+\s*=\s*["'][^"']*["']/gi, ""), s = s.replace(/javascript:/gi, ""), s = s.replace(/data:/gi, ""), "<div>" + s + "</div>";
                }
                return "<div>" + r(d) + "</div>";
              }, p.render.option_create = function(e, r) {
                return t.classList.contains("neo-autocreate") ? '<div class="create">Create <strong>' + r(e.input) + "</strong>&hellip;</div>" : null;
              }, new TomSelect(t, p);
            }
          });
        });
        o.observe(t);
      });
    }
  };
})(Drupal, once, Popper);
//# sourceMappingURL=tom-select.js.map
