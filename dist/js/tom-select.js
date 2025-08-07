(function(p, b, g) {
  const w = {
    dropdownParent: document.body,
    maxOptions: null,
    onInitialize: function() {
      const t = this, n = t.input, o = t.control;
      if (n.classList.contains("use-neo-tooltip") && p.behaviors.neoTooltip) {
        o.classList.add("use-neo-tooltip");
        for (const i in n.dataset)
          i.startsWith("tippy") && (o.dataset[i] = n.dataset[i]);
        p.behaviors.neoTooltip.attach(t.wrapper);
      }
      t.dropdownWatch = null, t.dropdownWatchCb = () => {
        if (t.isOpen) {
          t.popper.update();
          const i = t.wrapper.getBoundingClientRect();
          t.dropdown.style.width = Math.max(i.width, 140) + "px";
        }
      }, t.popper = g.createPopper(t.wrapper, t.dropdown, {
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
      const t = this, n = O(t.wrapper);
      n && t.dropdown.classList.add(n.schemeClass), t.dropdownWatchCb(), t.dropdownWatch = setInterval(t.dropdownWatchCb, 250);
    },
    onDropdownClose: function() {
      clearInterval(this.dropdownWatch);
    },
    onFocus: function() {
      p.behaviors.neoTooltip && p.behaviors.neoTooltip.disableAll();
    },
    onBlur: function() {
      p.behaviors.neoTooltip && p.behaviors.neoTooltip.enableAll();
    },
    render: {
      dropdown: function() {
        return '<div class="neo-tom-dropdown form--neo"></div>';
      }
    }
  };
  function O(t) {
    for (; t; ) {
      const n = Array.from(t.classList);
      for (const o of n)
        if (o.startsWith("scheme-"))
          return { element: t, schemeClass: o };
      t = t.parentElement;
    }
    return null;
  }
  function E(t) {
    const n = t.options;
    for (let o = 0; o < n.length; o++) {
      const i = n[o];
      if (i.value === "")
        return i;
    }
    return null;
  }
  p.behaviors.neoTomSelect = {
    attach: () => {
      b("neo.tom", "select.neo-select").forEach((t) => {
        if (t instanceof HTMLSelectElement) {
          var n = new IntersectionObserver((o, i) => {
            o.forEach((h) => {
              if (h.intersectionRatio > 0) {
                i.disconnect();
                const d = t.closest(".form--item");
                d && (d.classList.add("neo-tom-wrapper"), d.classList.add("neo-tom-select-wrapper"));
                let u = {
                  allowEmptyOption: E(t) !== null,
                  selectOnTab: !0,
                  plugins: {
                    dropdown_input: {}
                  },
                  placeholder: "Search..."
                };
                t.multiple && (u = { ...u, maxOptions: null, plugins: {
                  drag_drop: {},
                  remove_button: {
                    title: "Remove this item"
                  }
                } });
                const a = { ...u, ...w };
                a.render.item = function(r, f) {
                  return "<div>" + f(r.text) + "</div>";
                }, a.render.option = function(r, f) {
                  return "<div>" + f(r.text) + "</div>";
                };
                const m = new TomSelect(t, a);
                t.multiple && m.removeOption("_none");
              }
            });
          });
          n.observe(t);
        }
      }), b("neo.tom", "input.neo-entity-autocomplete").forEach((t) => {
        var n = new IntersectionObserver((o, i) => {
          o.forEach((h) => {
            if (h.intersectionRatio > 0) {
              i.disconnect();
              const d = t.parentElement, u = t.classList.contains("neo-multi-select");
              d && d.classList.add("neo-tom-wrapper");
              let a = {
                valueField: "value",
                labelField: "label",
                searchField: "label",
                create: !0,
                createOnBlur: !0,
                dropdownParent: document.body,
                maxItems: 1,
                load: function(e, s) {
                  const l = t.dataset.autocompletePath, c = l + (l.includes("?") ? "&" : "?") + "q=" + encodeURIComponent(e);
                  fetch(c).then((v) => v.json()).then((v) => {
                    s(v);
                  }).catch(() => {
                    s();
                  });
                }
              };
              const m = t.dataset.autocompleteFirstCharacterBlacklist || !1;
              m && (a.shouldLoad = function(e) {
                return !(e.length > 0 && m.includes(e[0]));
              }), u && (a = { ...a, maxItems: null, onItemAdd: function() {
                const e = this;
                e.setTextboxValue(""), e.refreshOptions();
              }, plugins: {
                drag_drop: {},
                remove_button: {
                  title: "Remove this item"
                }
              } });
              const r = { ...a, ...w };
              r.render.item = function(e, s) {
                return "<div>" + s(e[r.valueField]) + "</div>";
              }, r.render.option = function(e, s) {
                let l = e[r.labelField] || "";
                if (e.option) {
                  l = e.option;
                  let c = l.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, "");
                  return c = c.replace(/\s*on\w+\s*=\s*["'][^"']*["']/gi, ""), c = c.replace(/javascript:/gi, ""), c = c.replace(/data:/gi, ""), "<div>" + c + "</div>";
                }
                return "<div>" + s(l) + "</div>";
              }, r.render.option_create = function(e, s) {
                return t.classList.contains("neo-autocreate") ? '<div class="create">Create <strong>' + s(e.input) + "</strong>&hellip;</div>" : null;
              };
              const f = new TomSelect(t, r);
              u && f.on("change", function(e) {
                const s = f.input.closest("form");
                if (s) {
                  const l = new InputEvent("input", {
                    bubbles: !0,
                    cancelable: !0
                  });
                  s.dispatchEvent(l);
                }
              });
            }
          });
        });
        n.observe(t);
      });
    }
  };
})(Drupal, once, Popper);
//# sourceMappingURL=tom-select.js.map
