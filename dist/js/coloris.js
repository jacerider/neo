(function(o, l) {
  o.behaviors.neoColoris = {
    attach: () => {
      window.setTimeout(function() {
        l("neo.coloris", ".coloris").forEach((t) => {
          if (!t.classList.contains("coloris--processed")) {
            let u = t.getAttribute("id"), r = t.getAttribute("data-parent"), g = t.getAttribute("data-wrap"), s = t.getAttribute("data-theme") || "default", c = t.getAttribute("data-theme-mode") || "light", d = parseInt(t.getAttribute("data-margin") || "2"), h = t.getAttribute("data-format") || "hex", b = t.getAttribute("data-format-toggle"), f = t.getAttribute("data-alpha"), m = t.getAttribute("data-swatches-only"), A = t.getAttribute("data-focus-input"), S = t.getAttribute("data-clear-button-show") === "true", p = t.getAttribute("data-clear-button-label") || "", w = t.getAttribute("data-inline"), n = t.getAttribute("data-on-change"), C = t.getAttribute("data-default-color") || "", I = t.getAttribute("data-swatches"), O = JSON.parse(decodeURIComponent(I)), e = {
              el: t,
              wrap: g === "true",
              theme: s,
              themeMode: c,
              margin: d,
              format: h,
              formatToggle: b === "true",
              alpha: f === "true",
              swatchesOnly: m === "true",
              focusInput: A === "true",
              clearButton: {
                show: S,
                label: p
              },
              swatches: O,
              inline: w === "true",
              defaultColor: C
            };
            if (r !== null && (e.parent = r), n !== null) {
              const i = ((y, B = self, T = ".") => {
                var L = y.split(T);
                return L.reduce((a, M) => a == null ? void 0 : a[M], B);
              })(n);
              i && (e.onChange = i);
            }
            Coloris.setInstance("#" + u, e), Coloris(e);
          }
        });
      });
    }
  };
})(Drupal, once);
//# sourceMappingURL=coloris.js.map
