/**
 * Neo Disable Module
 *
 * Provides functionality to disable buttons, forms, and other elements during AJAX operations
 * or form submissions to prevent multiple submissions and improve user experience.
 */
(function ($, Drupal, once): void {

  // Track which trigger initiated a form submission
  let activeTrigger: HTMLElement | null = null;

  /**
   * Interface for NeoDisable configuration options
   */
  interface NeoDisableOptions {
    disabledClasses: string[];
  }

  /**
   * NeoDisable class - handles disabling elements during asynchronous operations
   */
  class NeoDisable {
    readonly trigger: HTMLElement;
    readonly form: HTMLFormElement | null;
    readonly options: NeoDisableOptions;
    private clone: HTMLElement | null;

    /**
     * Creates a new NeoDisable instance
     *
     * @param trigger - The element that triggers the disable behavior
     * @param options - Optional configuration options
     */
    constructor(trigger: HTMLElement, options: Partial<NeoDisableOptions> = {}) {
      this.trigger = trigger;
      this.clone = null;
      this.form = this.trigger.closest('form');
      this.options = {
        disabledClasses: ['opacity-50', 'pointer-events-none'],
        ...options
      };

      this.initEvents();
    }

    /**
     * Initialize event listeners
     */
    private initEvents(): void {
      // Handle trigger mousedown events
      this.trigger.addEventListener('mousedown', this.handleTriggerClick.bind(this));

      // Set up form submission handler if this is the first NeoDisable for this form
      if (this.form && !this.form.dataset.neoDisable) {
        this.form.dataset.neoDisable = 'true';
        this.form.addEventListener('submit', this.handleFormSubmit.bind(this));
      }
    }

    /**
     * Handle trigger click events
     */
    private handleTriggerClick(_e: MouseEvent): void {
      // Store active trigger for form submissions
      if (this.form) {
        activeTrigger = this.trigger;
      }

      const onceValue = this.trigger.dataset.once || '';

      if (onceValue.includes('drupal-ajax')) {
        this.handleAjaxTrigger();
      } else if (!this.form) {
        // If not a form element, disable the trigger directly
        this.disable(this.trigger, this.trigger);
      }
    }

    /**
     * Handle AJAX triggers with appropriate disable/enable lifecycle
     */
    private handleAjaxTrigger(): void {
      $(document).one('ajaxStart', () => {
        // Use requestAnimationFrame for more reliable timing
        requestAnimationFrame(() => {
          this.disable(this.form || this.trigger, this.trigger);
        });

        $(document).one('ajaxComplete', () => {
          requestAnimationFrame(() => {
            this.enable(this.form || this.trigger, this.trigger);
          });
        });
      });
    }

    /**
     * Handle form submission
     */
    private handleFormSubmit(_e: SubmitEvent): void {
      if (activeTrigger) {
        this.disable(this.form as HTMLElement, activeTrigger);
      }
    }

    /**
     * Disable an element and optionally replace trigger with loading message
     *
     * @param element - The element to disable
     * @param trigger - The trigger element that may show a loading message
     */
    disable(element: HTMLElement, trigger: HTMLElement | null = null): void {
      activeTrigger = null;
      if (!element) return;

      // Add disabled classes
      element.classList.add(...this.options.disabledClasses);

      // Handle loading message if specified
      if (trigger && trigger.dataset.neoDisableMessage) {
        try {
          this.showLoadingMessage(trigger);
        } catch (err) {
          console.error('Error showing loading message:', err);
        }
      }
    }

    /**
     * Create and display loading message clone
     *
     * @param trigger - The trigger element
     */
    private showLoadingMessage(trigger: HTMLElement): void {
      const message = Drupal.t(trigger.dataset.neoDisableMessage || '');
      if (!message) return;

      // Clone the trigger element
      this.clone = trigger.cloneNode(true) as HTMLElement;
      this.clone.style.minWidth = `${trigger.offsetWidth}px`;
      // this.clone.classList.add('btn-ignore');

      // Set appropriate text content based on element type
      if (this.clone instanceof HTMLButtonElement ||
          this.clone instanceof HTMLAnchorElement) {
        this.clone.innerHTML = message;
      } else if (this.clone instanceof HTMLInputElement && this.clone.type === 'submit') {
        this.clone.value = message;
      }

      // Insert clone and hide original
      trigger.after(this.clone);
      trigger.style.display = 'none';
    }

    /**
     * Re-enable an element and restore original trigger
     *
     * @param element - The element to enable
     * @param trigger - The trigger element to restore
     */
    enable(element: HTMLElement, trigger: HTMLElement | null = null): void {
      // Ensure element is still in the DOM
      if (!element || !document.body.contains(element)) return;

      // Remove disabled classes
      element.classList.remove(...this.options.disabledClasses);

      // Restore original trigger if clone exists
      if (trigger && this.clone) {
        trigger.style.display = '';
        this.clone.remove();
        this.clone = null;
      }
    }
  }

  // Store original AJAX behavior to restore later
  const originalAjaxBehavior = Drupal.behaviors.AJAX || null;
  if (originalAjaxBehavior) {
    delete Drupal.behaviors.AJAX;
  }

  /**
   * Register Drupal behavior
   */
  Drupal.behaviors.neoDisable = {
    attach: (context: any, settings: any): void => {
      // Initialize NeoDisable on elements with .neo-disable class
      once('neo-disable', '.neo-disable', context).forEach((el) => {
        if (el instanceof HTMLElement) {
          new NeoDisable(el);
        }
      });

      // Restore and run original AJAX behavior after our initialization
      if (originalAjaxBehavior) {
        Drupal.behaviors.AJAX = originalAjaxBehavior;
        Drupal.behaviors.AJAX.attach(context, settings);
      }
    }
  };

})(jQuery, Drupal, once);

export {};
