(function(l, v, b) {
  const w = {
    dropdownParent: document.body,
    maxOptions: null,
    onInitialize: function() {
      const t = this, n = t.input, o = t.control;
      if (n.classList.contains("use-neo-tooltip") && l.behaviors.neoTooltip) {
        o.classList.add("use-neo-tooltip");
        for (const i in n.dataset)
          i.startsWith("tippy") && (o.dataset[i] = n.dataset[i]);
        l.behaviors.neoTooltip.attach(t.wrapper);
      }
      t.dropdownWatch = null, t.dropdownWatchCb = () => {
        if (t.isOpen) {
          t.popper.update();
          const i = t.wrapper.getBoundingClientRect();
          t.dropdown.style.width = Math.max(i.width, 140) + "px";
        }
      }, t.popper = b.createPopper(t.wrapper, t.dropdown, {
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
      const t = this, n = g(t.wrapper);
      n && t.dropdown.classList.add(n.schemeClass), t.dropdownWatchCb(), t.dropdownWatch = setInterval(t.dropdownWatchCb, 250);
    },
    onDropdownClose: function() {
      clearInterval(this.dropdownWatch);
    },
    onFocus: function() {
      l.behaviors.neoTooltip && l.behaviors.neoTooltip.disableAll();
    },
    onBlur: function() {
      l.behaviors.neoTooltip && l.behaviors.neoTooltip.enableAll();
    },
    render: {
      dropdown: function() {
        return '<div class="neo-tom-dropdown neo-form"></div>';
      }
    }
  };
  function g(t) {
    for (; t; ) {
      const n = Array.from(t.classList);
      for (const o of n)
        if (o.startsWith("scheme-"))
          return { element: t, schemeClass: o };
      t = t.parentElement;
    }
    return null;
  }
  function O(t) {
    const n = t.options;
    for (let o = 0; o < n.length; o++) {
      const i = n[o];
      if (i.value === "")
        return i;
    }
    return null;
  }
  l.behaviors.neoTomSelect = {
    attach: () => {
      v("neo.tom", "select.neo-select").forEach((t) => {
        if (t instanceof HTMLSelectElement) {
          var n = new IntersectionObserver((o, i) => {
            o.forEach((m) => {
              if (m.intersectionRatio > 0) {
                i.disconnect();
                const p = t.closest(".form--item");
                p && (p.classList.add("neo-tom-wrapper"), p.classList.add("neo-tom-select-wrapper"));
                let u = {
                  allowEmptyOption: O(t) !== null,
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
                const c = { ...u, ...w };
                c.render.item = function(s, e) {
                  return "<div>" + e(s.text) + "</div>";
                }, c.render.option = function(s, e) {
                  return "<div>" + e(s.text) + "</div>";
                };
                const f = new TomSelect(t, c);
                t.multiple && f.removeOption("_none");
              }
            });
          });
          n.observe(t);
        }
      }), v("neo.tom", "input.neo-entity-autocomplete").forEach((t) => {
        var n = new IntersectionObserver((o, i) => {
          o.forEach((m) => {
            if (m.intersectionRatio > 0) {
              i.disconnect();
              const p = t.parentElement, u = t.classList.contains("neo-multi-select");
              p && p.classList.add("neo-tom-wrapper");
              let c = {
                valueField: "value",
                labelField: "label",
                searchField: "label",
                create: !0,
                createOnBlur: !0,
                dropdownParent: document.body,
                maxItems: 1,
                load: function(e, a) {
                  const d = t.dataset.autocompletePath, r = d + (d.includes("?") ? "&" : "?") + "q=" + encodeURIComponent(e);
                  fetch(r).then((h) => h.json()).then((h) => {
                    a(h);
                  }).catch(() => {
                    a();
                  });
                }
              };
              const f = t.dataset.autocompleteFirstCharacterBlacklist || !1;
              f && (c.shouldLoad = function(e) {
                return !(e.length > 0 && f.includes(e[0]));
              }), u && (c = { ...c, maxItems: null, onItemAdd: function() {
                const e = this;
                e.setTextboxValue(""), e.refreshOptions();
              }, plugins: {
                remove_button: {
                  title: "Remove this item"
                }
              } });
              const s = { ...c, ...w };
              s.render.item = function(e, a) {
                return "<div>" + a(e[s.valueField]) + "</div>";
              }, s.render.option = function(e, a) {
                let d = e[s.labelField] || "";
                if (e.option) {
                  d = e.option;
                  let r = d.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, "");
                  return r = r.replace(/\s*on\w+\s*=\s*["'][^"']*["']/gi, ""), r = r.replace(/javascript:/gi, ""), r = r.replace(/data:/gi, ""), "<div>" + r + "</div>";
                }
                return "<div>" + a(d) + "</div>";
              }, s.render.option_create = function(e, a) {
                return t.classList.contains("neo-autocreate") ? '<div class="create">Create <strong>' + a(e.input) + "</strong>&hellip;</div>" : null;
              }, new TomSelect(t, s);
            }
          });
        });
        n.observe(t);
      });
    }
  };
})(Drupal, once, Popper);
//# sourceMappingURL=tom-select.js.map
