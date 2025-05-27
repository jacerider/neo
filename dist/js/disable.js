(function(a, s, l) {
  let t = null;
  class r {
    /**
     * Creates a new NeoDisable instance
     *
     * @param trigger - The element that triggers the disable behavior
     * @param options - Optional configuration options
     */
    constructor(e, i = {}) {
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
      if (e.button === 2)
        return;
      this.form && (t = this.trigger), (this.trigger.dataset.once || "").includes("drupal-ajax") ? this.handleAjaxTrigger() : this.form || setTimeout(() => {
        this.disable(this.trigger, this.trigger);
      }, 100);
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
    disable(e, i = null) {
      if (t = null, !!e && (e.classList.add(...this.options.disabledClasses), i && i.dataset.neoDisableMessage))
        try {
          this.showLoadingMessage(i);
        } catch (h) {
          console.error("Error showing loading message:", h);
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
  const n = s.behaviors.AJAX || null;
  n && delete s.behaviors.AJAX, s.behaviors.neoDisable = {
    attach: (o, e) => {
      l("neo-disable", ".neo-disable", o).forEach((i) => {
        i instanceof HTMLElement && new r(i);
      }), n && (s.behaviors.AJAX = n, s.behaviors.AJAX.attach(o, e));
    }
  };
})(jQuery, Drupal, once);
//# sourceMappingURL=disable.js.map
