/**
 * Neo Inline Entity Form widget.
 *
 * The widget's open forms are neo shelves (neo/shelf), which handle the
 * dialog. This adds what only IEF has:
 * - the remove confirmation, which stays inline under its row: Esc cancels
 *   it, its Cancel takes focus when it opens, and focus goes back to the
 *   Remove button when it closes;
 * - nested widgets: what they apply survives a shelf's Cancel, so their AJAX
 *   updates do not make the shelf dirty, but entries added inside a new
 *   entity are dropped with it;
 * - AJAX messages, which core puts behind the shelf.
 *
 * Every IEF button is an AJAX button that replaces the whole widget, so a
 * confirmation is rebuilt from scratch on each round trip. Its state is kept
 * per key (widget id and row), which survives that.
 */
(function (Drupal): void {

  const CONFIRM = '[data-neo-ief-confirm]';
  const WRAPPER = '[id^="inline-entity-form-"]';

  interface NeoShelf {
    dirtyChecks: Array<(shelf: HTMLElement) => boolean>;
    escapeHandlers: Array<(event: KeyboardEvent, top: HTMLElement | null) => boolean>;
    quietAjax: string[];
    nearest: (node: EventTarget | Node | null) => HTMLElement | null;
    part: (shelf: HTMLElement, name: string) => HTMLElement | null;
    press: (button: HTMLElement) => void;
    isVisible: (el: HTMLElement) => boolean;
  }
  const shelf = (Drupal as unknown as { neoShelf: NeoShelf }).neoShelf;

  // Confirmations known to be open, by key, with the buttons to focus once
  // each closes.
  const openConfirms = new Map<string, string[]>();
  const seen = new WeakSet<HTMLElement>();
  let syncFrame = 0;

  function keyOf(confirm: HTMLElement): string {
    return confirm.dataset.neoIefConfirm || '';
  }

  /**
   * The open remove confirmation that belongs to a shelf, or to the page.
   */
  function openConfirm(top: HTMLElement | null): HTMLElement | null {
    const scope: ParentNode = top ? shelf.part(top, 'body') || top : document;
    const confirms = Array.from(scope.querySelectorAll<HTMLElement>(CONFIRM))
      .filter((el) => shelf.nearest(el) === top && shelf.isVisible(el));
    return confirms.length ? confirms[confirms.length - 1] : null;
  }

  function byName(name: string): HTMLElement | null {
    const el = document.querySelector<HTMLElement>(`[name="${CSS.escape(name)}"]`);
    return el && shelf.isVisible(el) ? el : null;
  }

  /**
   * The button to focus once a confirmation closed.
   */
  function findReturnTarget(key: string): HTMLElement | null {
    for (const name of openConfirms.get(key) || []) {
      const el = byName(name);
      if (el) {
        return el;
      }
    }
    // Nothing named survived, so the first button left in the widget.
    const wrapper = document.getElementById('inline-entity-form-' + key.split('|')[0]);
    if (!wrapper) {
      return null;
    }
    return Array.from(wrapper.querySelectorAll<HTMLElement>(
      'button:not([disabled]), input[type="submit"]:not([disabled])',
    )).find(shelf.isVisible) || null;
  }

  /**
   * Moves AJAX messages in front of the form they are about.
   *
   * Core prepends them to the widget wrapper, which is behind the shelf.
   */
  function moveMessages(): void {
    document.querySelectorAll<HTMLElement>(`${WRAPPER} > [data-drupal-messages]`)
      .forEach((messages) => {
        const wrapper = messages.parentElement;
        if (!wrapper) {
          return;
        }
        const shelves = Array.from(wrapper.querySelectorAll<HTMLElement>('.neo-shelf'))
          .filter(shelf.isVisible);
        const top = shelves[shelves.length - 1];
        const body = top ? shelf.part(top, 'body') : null;
        if (body) {
          body.prepend(messages);
        }
      });
  }

  // Runs after neo's shelf sync, which was scheduled first, so the focus
  // set here wins.
  function scheduleSync(): void {
    if (syncFrame) {
      return;
    }
    syncFrame = window.requestAnimationFrame(() => {
      syncFrame = 0;
      sync();
    });
  }

  function sync(): void {
    moveMessages();

    const confirms = Array.from(document.querySelectorAll<HTMLElement>(CONFIRM));
    const present = new Set(confirms.map(keyOf));

    let returnTo: HTMLElement | null = null;
    Array.from(openConfirms.keys()).forEach((key) => {
      if (present.has(key)) {
        return;
      }
      returnTo = returnTo || findReturnTarget(key);
      openConfirms.delete(key);
    });

    let opened: HTMLElement | null = null;
    confirms.filter(shelf.isVisible).forEach((confirm) => {
      if (seen.has(confirm)) {
        return;
      }
      seen.add(confirm);
      const key = keyOf(confirm);
      if (!openConfirms.has(key)) {
        openConfirms.set(key, (confirm.dataset.neoIefReturn || '').split(' ').filter(Boolean));
        opened = confirm;
      }
    });

    if (opened) {
      (opened as HTMLElement).querySelector<HTMLElement>('[data-neo-ief-cancel]')?.focus();
      return;
    }
    (returnTo as HTMLElement | null)?.focus();
  }

  shelf.quietAjax.push(WRAPPER);

  // Entries added inside a new entity are dropped with it. In an edit form
  // they are kept, as IEF keeps them, so there is nothing to lose.
  shelf.dirtyChecks.push((el) => {
    const op = el.dataset.neoIefOp;
    const body = shelf.part(el, 'body');
    if (!body || (op !== 'add' && op !== 'duplicate')) {
      return false;
    }
    return Array.from(body.querySelectorAll('[data-neo-ief-pending="true"]'))
      .some((pending) => shelf.nearest(pending) === el);
  });

  shelf.escapeHandlers.push((event, top) => {
    const confirm = openConfirm(top);
    if (!confirm || !(top || confirm.contains(event.target as Node))) {
      return false;
    }
    const cancel = confirm.querySelector<HTMLElement>('[data-neo-ief-cancel]');
    if (!cancel) {
      return false;
    }
    event.preventDefault();
    shelf.press(cancel);
    return true;
  });

  Drupal.behaviors.neoInlineEntityFormWidget = {
    attach(): void {
      scheduleSync();
    },
    detach(): void {
      scheduleSync();
    },
  };

})(Drupal);
