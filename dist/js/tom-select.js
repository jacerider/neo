(function(l, v, w) {
  const b = {
    dropdownParent: document.body,
    maxOptions: null,
    onInitialize: function() {
      const t = this, i = t.input, s = t.control;
      if (i.classList.contains("use-neo-tooltip") && l.behaviors.neoTooltip) {
        s.classList.add("use-neo-tooltip");
        for (const n in i.dataset)
          n.startsWith("tippy") && (s.dataset[n] = i.dataset[n]);
        l.behaviors.neoTooltip.attach(t.wrapper);
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
      l.behaviors.neoTooltip && l.behaviors.neoTooltip.disableAll();
    },
    onBlur: function() {
      l.behaviors.neoTooltip && l.behaviors.neoTooltip.enableAll();
    },
    render: {
      dropdown: function() {
        return '<div class="neo-tom-dropdown"></div>';
      }
    }
  };
  function g(t) {
    const i = t.options;
    for (let s = 0; s < i.length; s++) {
      const n = i[s];
      if (n.value === "")
        return n;
    }
    return null;
  }
  l.behaviors.neoTomSelect = {
    attach: () => {
      v("neo.tom", "select.neo-select").forEach((t) => {
        if (t instanceof HTMLSelectElement) {
          var i = new IntersectionObserver((s, n) => {
            s.forEach((h) => {
              if (h.intersectionRatio > 0) {
                n.disconnect();
                const p = t.closest(".form--item");
                p && (p.classList.add("neo-tom-wrapper"), p.classList.add("neo-tom-select-wrapper"));
                let d = {
                  allowEmptyOption: g(t) !== null,
                  selectOnTab: !0,
                  plugins: {
                    dropdown_input: {}
                  },
                  placeholder: "Search..."
                };
                t.multiple && (d = { ...d, maxOptions: null, plugins: {
                  remove_button: {
                    title: "Remove this item"
                  }
                } }), console.log(d);
                const c = { ...d, ...b };
                c.render.item = function(o, e) {
                  return "<div>" + e(o.text) + "</div>";
                }, c.render.option = function(o, e) {
                  return console.log(o), "<div>" + e(o.text) + "</div>";
                };
                const f = new TomSelect(t, c);
                t.multiple && f.removeOption("_none");
              }
            });
          });
          i.observe(t);
        }
      }), v("neo.tom", "input.neo-entity-autocomplete").forEach((t) => {
        var i = new IntersectionObserver((s, n) => {
          s.forEach((h) => {
            if (h.intersectionRatio > 0) {
              n.disconnect();
              const p = t.parentElement, d = t.classList.contains("neo-multi-select");
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
                  const u = t.dataset.autocompletePath, r = u + (u.includes("?") ? "&" : "?") + "q=" + encodeURIComponent(e);
                  fetch(r).then((m) => m.json()).then((m) => {
                    a(m);
                  }).catch(() => {
                    a();
                  });
                }
              };
              const f = t.dataset.autocompleteFirstCharacterBlacklist || !1;
              f && (c.shouldLoad = function(e) {
                return !(e.length > 0 && f.includes(e[0]));
              }), d && (c = { ...c, maxItems: null, onItemAdd: function() {
                const e = this;
                e.setTextboxValue(""), e.refreshOptions();
              }, plugins: {
                remove_button: {
                  title: "Remove this item"
                }
              } });
              const o = { ...c, ...b };
              o.render.item = function(e, a) {
                return "<div>" + a(e[o.valueField]) + "</div>";
              }, o.render.option = function(e, a) {
                let u = e[o.labelField] || "";
                if (e.option) {
                  u = e.option;
                  let r = u.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, "");
                  return r = r.replace(/\s*on\w+\s*=\s*["'][^"']*["']/gi, ""), r = r.replace(/javascript:/gi, ""), r = r.replace(/data:/gi, ""), "<div>" + r + "</div>";
                }
                return "<div>" + a(u) + "</div>";
              }, o.render.option_create = function(e, a) {
                return t.classList.contains("neo-autocreate") ? '<div class="create">Create <strong>' + a(e.input) + "</strong>&hellip;</div>" : null;
              }, new TomSelect(t, o);
            }
          });
        });
        i.observe(t);
      });
    }
  };
})(Drupal, once, Popper);
//# sourceMappingURL=tom-select.js.map
