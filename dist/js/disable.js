(function(a, i, l) {
  let t = null;
  class h {
    /**
     * Creates a new NeoDisable instance
     *
     * @param trigger - The element that triggers the disable behavior
     * @param options - Optional configuration options
     */
    constructor(e, s = {}) {
      this.trigger = e, this.clone = null, this.form = this.trigger.closest("form"), this.options = {
        disabledClasses: ["opacity-50", "pointer-events-none"],
        ...s
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
      this.form && (t = this.trigger), (this.trigger.dataset.once || "").includes("drupal-ajax") ? this.handleAjaxTrigger() : this.form || this.disable(this.trigger, this.trigger);
    }
    /**
     * Handle AJAX triggers with appropriate disable/enable lifecycle
     */
    handleAjaxTrigger() {
      a(document).one("ajaxStart", () => {
        requestAnimationFrame(() => {
          this.disable(this.form || this.trigger, this.trigger);
        }), a(document).one("ajaxComplete", () => {
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
      t && this.disable(this.form, t);
    }
    /**
     * Disable an element and optionally replace trigger with loading message
     *
     * @param element - The element to disable
     * @param trigger - The trigger element that may show a loading message
     */
    disable(e, s = null) {
      if (t = null, !!e && (e.classList.add(...this.options.disabledClasses), s && s.dataset.neoDisableMessage))
        try {
          this.showLoadingMessage(s);
        } catch (r) {
          console.error("Error showing loading message:", r);
        }
    }
    /**
     * Create and display loading message clone
     *
     * @param trigger - The trigger element
     */
    showLoadingMessage(e) {
      const s = i.t(e.dataset.neoDisableMessage || "");
      s && (this.clone = e.cloneNode(!0), this.clone.style.minWidth = `${e.offsetWidth}px`, this.clone instanceof HTMLButtonElement || this.clone instanceof HTMLAnchorElement ? this.clone.innerHTML = s : this.clone instanceof HTMLInputElement && this.clone.type === "submit" && (this.clone.value = s), e.after(this.clone), e.style.display = "none");
    }
    /**
     * Re-enable an element and restore original trigger
     *
     * @param element - The element to enable
     * @param trigger - The trigger element to restore
     */
    enable(e, s = null) {
      !e || !document.body.contains(e) || (e.classList.remove(...this.options.disabledClasses), s && this.clone && (s.style.display = "", this.clone.remove(), this.clone = null));
    }
  }
  const n = i.behaviors.AJAX || null;
  n && delete i.behaviors.AJAX, i.behaviors.neoDisable = {
    attach: (o, e) => {
      l("neo-disable", ".neo-disable", o).forEach((s) => {
        s instanceof HTMLElement && new h(s);
      }), n && (i.behaviors.AJAX = n, i.behaviors.AJAX.attach(o, e));
    }
  };
})(jQuery, Drupal, once);
//# sourceMappingURL=disable.js.map
