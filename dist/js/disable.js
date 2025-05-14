var d = Object.defineProperty;
var f = (t, s, n) => s in t ? d(t, s, { enumerable: !0, configurable: !0, writable: !0, value: n }) : t[s] = n;
var o = (t, s, n) => f(t, typeof s != "symbol" ? s + "" : s, n);
(function(t, s, n) {
  let a = null;
  class h {
    /**
     * Creates a new NeoDisable instance
     *
     * @param trigger - The element that triggers the disable behavior
     * @param options - Optional configuration options
     */
    constructor(e, i = {}) {
      o(this, "trigger");
      o(this, "form");
      o(this, "options");
      o(this, "clone");
      this.trigger = e, this.clone = null, this.form = this.trigger.closest("form"), this.options = {
        disabledClasses: ["opacity-50", "pointer-events-none"],
        ...i
      }, this.initEvents();
    }
    /**
     * Initialize event listeners
     */
    initEvents() {
      this.trigger.addEventListener("mousedown", this.handleTriggerClick.bind(this)), this.form && !this.form.dataset.neoDisable && (this.form.dataset.neoDisable = "true", this.form.addEventListener("submit", this.handleFormSubmit.bind(this)));
    }
    /**
     * Handle trigger click events
     */
    handleTriggerClick(e) {
      this.form && (a = this.trigger), (this.trigger.dataset.once || "").includes("drupal-ajax") ? this.handleAjaxTrigger() : this.form || this.disable(this.trigger, this.trigger);
    }
    /**
     * Handle AJAX triggers with appropriate disable/enable lifecycle
     */
    handleAjaxTrigger() {
      t(document).one("ajaxStart", () => {
        requestAnimationFrame(() => {
          this.disable(this.form || this.trigger, this.trigger);
        }), t(document).one("ajaxComplete", () => {
          requestAnimationFrame(() => {
            this.enable(this.form || this.trigger, this.trigger);
          });
        });
      });
    }
    /**
     * Handle form submission
     */
    handleFormSubmit(e) {
      a && this.disable(this.form, a);
    }
    /**
     * Disable an element and optionally replace trigger with loading message
     *
     * @param element - The element to disable
     * @param trigger - The trigger element that may show a loading message
     */
    disable(e, i = null) {
      if (a = null, !!e && (e.classList.add(...this.options.disabledClasses), i && i.dataset.neoDisableMessage))
        try {
          this.showLoadingMessage(i);
        } catch (c) {
          console.error("Error showing loading message:", c);
        }
    }
    /**
     * Create and display loading message clone
     *
     * @param trigger - The trigger element
     */
    showLoadingMessage(e) {
      const i = s.t(e.dataset.neoDisableMessage || "");
      i && (this.clone = e.cloneNode(!0), this.clone.style.minWidth = `${e.offsetWidth}px`, this.clone instanceof HTMLButtonElement || this.clone instanceof HTMLAnchorElement ? this.clone.innerHTML = i : this.clone instanceof HTMLInputElement && this.clone.type === "submit" && (this.clone.value = i), e.after(this.clone), e.style.display = "none");
    }
    /**
     * Re-enable an element and restore original trigger
     *
     * @param element - The element to enable
     * @param trigger - The trigger element to restore
     */
    enable(e, i = null) {
      !e || !document.body.contains(e) || (e.classList.remove(...this.options.disabledClasses), i && this.clone && (i.style.display = "", this.clone.remove(), this.clone = null));
    }
  }
  const l = s.behaviors.AJAX || null;
  l && delete s.behaviors.AJAX, s.behaviors.neoDisable = {
    attach: (r, e) => {
      n("neo-disable", ".neo-disable", r).forEach((i) => {
        i instanceof HTMLElement && new h(i);
      }), l && (s.behaviors.AJAX = l, s.behaviors.AJAX.attach(r, e));
    }
  };
})(jQuery, Drupal, once);
//# sourceMappingURL=disable.js.map
