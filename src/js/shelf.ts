/**
 * Neo shelves.
 *
 * A shelf is a container, left where its builder put it, that CSS fixes over
 * the page as a side panel (see \Drupal\neo\Shelf). This behavior makes the
 * shelf behave like a dialog. It adds the dialog semantics, the trail of
 * shelves it sits in, focus handling, Esc and Enter, and the question before
 * unsaved changes are thrown away.
 *
 * A shelf is open while it is shown. It closes either by going away, when an
 * AJAX response replaces it, or by being hidden with Drupal.neoShelf.close().
 * Either way it slides out: a hidden shelf plays the animation before it is
 * hidden, and a shelf an AJAX response removes is copied as it goes, into an
 * inert ghost that plays it in its place.
 * The state here is kept per shelf key, which survives a shelf being rebuilt
 * over AJAX, and everything is resolved again in sync() after each attach and
 * detach.
 *
 * Code that builds on shelves uses Drupal.neoShelf: it can open and close a
 * shelf it renders closed, add its own reasons a shelf is dirty, handle Esc
 * first, and name AJAX wrappers inside a shelf whose updates are not changes.
 * Each shelf also fires `neo-shelf:open` and `neo-shelf:close`, which bubble.
 */
(function ($, Drupal, once): void {

  // Ghosts carry the class for its styles, and are not shelves.
  const SHELF = '.neo-shelf:not(.is-ghost)';

  // Popups that other code appends to the body, and the displaced regions
  // (the toolbar) that the shelf leaves uncovered. Focus may go to them while
  // a shelf is open.
  const FOCUS_ALLOWED = [
    '[data-offset-top]',
    '[data-offset-right]',
    '[data-offset-bottom]',
    '[data-offset-left]',
    '.neo-modals',
    '.neo-modal',
    '.ck-body-wrapper',
    '[data-tippy-root]',
    '.ts-dropdown',
    '.ui-autocomplete',
    '.ui-dialog',
    '#drupal-live-announce',
  ].join(', ');

  const FIELDS = [
    'input:not([type="hidden"]):not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[contenteditable="true"]',
  ].join(', ');

  const FOCUSABLE = [
    FIELDS,
    'a[href]',
    'button:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
  ].join(', ');

  // Single-line inputs where Enter means "done".
  const TEXT_TYPES = [
    'text', 'search', 'email', 'tel', 'url', 'number', 'password',
    'date', 'datetime-local', 'month', 'time', 'week',
  ];

  // Shelves known to be open, by key.
  const openKeys = new Set<string>();
  // Button names to focus once a shelf closes, in order of preference.
  const returnTargets = new Map<string, string[]>();
  // The element to find a button in when none of those is left.
  const owners = new Map<string, string>();
  // Shelves whose fields changed since they opened.
  const dirtyKeys = new Set<string>();
  // When each shelf opened, to ignore the change events editors fire while
  // they start up.
  const openedAt = new Map<string, number>();
  // Shelf elements already taken in. A re-rendered shelf is a new element
  // with a known key; a reopened one is forgotten when it closes.
  const seen = new WeakSet<HTMLElement>();
  // Copies of shelves an AJAX response is removing, by key, until sync()
  // knows whether each came back.
  const ghosts = new Map<string, HTMLElement>();
  // Cancels the slide-out of a shelf still playing it.
  const leaving = new WeakMap<HTMLElement, () => void>();

  let syncFrame = 0;
  let escOwnedElsewhere = false;
  let pointerDownTarget: EventTarget | null = null;

  // A shelf inside a field group tab that is not shown is not open as far as
  // the page is concerned. Watching visibility catches the tab switch.
  const observer = 'IntersectionObserver' in window
    ? new IntersectionObserver(() => scheduleSync())
    : null;

  function keyOf(shelf: HTMLElement): string {
    return shelf.dataset.neoShelf || '';
  }

  function isVisible(el: HTMLElement): boolean {
    return typeof el.checkVisibility === 'function'
      ? el.checkVisibility()
      : el.getClientRects().length > 0;
  }

  function isClosed(shelf: HTMLElement): boolean {
    return shelf.classList.contains('is-closed') || shelf.classList.contains('is-leaving');
  }

  /**
   * Whether a shelf is open on the page: shown, and not sliding out.
   */
  function isShown(shelf: HTMLElement): boolean {
    return isVisible(shelf) && !shelf.classList.contains('is-leaving');
  }

  function reducedMotion(): boolean {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function nearestShelf(node: EventTarget | Node | null): HTMLElement | null {
    const el = node instanceof Element
      ? node
      : node instanceof Node ? node.parentElement : null;
    return el ? el.closest<HTMLElement>(SHELF) : null;
  }

  function parentShelf(shelf: HTMLElement): HTMLElement | null {
    return nearestShelf(shelf.parentElement);
  }

  function activeShelves(): HTMLElement[] {
    return Array.from(document.querySelectorAll<HTMLElement>(SHELF))
      .filter(isShown);
  }

  function topShelf(): HTMLElement | null {
    const shelves = activeShelves();
    return shelves.length ? shelves[shelves.length - 1] : null;
  }

  function part(shelf: HTMLElement, name: string): HTMLElement | null {
    return shelf.querySelector<HTMLElement>(
      ':scope > .neo-shelf__panel > .neo-shelf__' + name,
    );
  }

  function panelOf(shelf: HTMLElement): HTMLElement | null {
    return shelf.querySelector<HTMLElement>(':scope > .neo-shelf__panel');
  }

  function footerButton(shelf: HTMLElement, attribute: string): HTMLElement | null {
    return part(shelf, 'footer')?.querySelector<HTMLElement>(`[${attribute}]`) || null;
  }

  function modalOpen(): boolean {
    const neoModal = (Drupal as unknown as {
      neoModal?: { getTop?: () => unknown };
    }).neoModal;
    return !!neoModal?.getTop?.();
  }

  function byName(name: string): HTMLElement | null {
    const el = document.querySelector<HTMLElement>(`[name="${CSS.escape(name)}"]`);
    return el && isVisible(el) ? el : null;
  }

  function emit(shelf: HTMLElement | null, type: 'open' | 'close', key: string): void {
    const target: EventTarget = shelf?.isConnected ? shelf : document;
    target.dispatchEvent(new CustomEvent('neo-shelf:' + type, {
      bubbles: true,
      detail: { key, shelf },
    }));
  }

  /**
   * Presses a footer button.
   *
   * Drupal binds AJAX buttons to mousedown and cancels their click, so a
   * click() would do nothing. A button Drupal disabled is mid-request. A
   * plain `type="button"` belongs to script on the page, which listens for
   * its click.
   */
  function press(button: HTMLElement): void {
    if ((button as HTMLButtonElement).disabled) {
      return;
    }
    if ((button as HTMLButtonElement).type === 'button') {
      button.click();
      return;
    }
    $(button).trigger('mousedown');
  }

  /**
   * Whether closing a shelf would throw work away.
   */
  function isDirty(shelf: HTMLElement): boolean {
    if (dirtyKeys.has(keyOf(shelf))) {
      return true;
    }
    const body = part(shelf, 'body');
    if (!body) {
      return false;
    }
    const own = (el: Element) => nearestShelf(el) === shelf;
    // Tabledrag reorders rows without firing change events.
    if (Array.from(body.querySelectorAll('.tabledrag-changed')).some(own)) {
      return true;
    }
    return api.dirtyChecks.some((check) => check(shelf));
  }

  /**
   * Closes a shelf through its Cancel button, asking first if needed.
   */
  function requestClose(shelf: HTMLElement): void {
    const cancel = footerButton(shelf, 'data-neo-shelf-cancel');
    if (!cancel) {
      return;
    }
    if (isDirty(shelf) && !window.confirm(
      shelf.dataset.neoShelfConfirm || Drupal.t('Discard your changes?'),
    )) {
      return;
    }
    press(cancel);
  }

  function buildTrail(shelf: HTMLElement): void {
    const trail = part(shelf, 'header')
      ?.querySelector<HTMLElement>('.neo-shelf__trail');
    if (!trail) {
      return;
    }
    const chain: HTMLElement[] = [];
    for (let ancestor = parentShelf(shelf); ancestor; ancestor = parentShelf(ancestor)) {
      chain.unshift(ancestor);
    }
    const parts: string[] = [];
    chain.forEach((ancestor) => {
      parts.push(ancestor.dataset.neoShelfSection || '');
      parts.push(ancestor.dataset.neoShelfLabel || '');
    });
    parts.push(shelf.dataset.neoShelfSection || '');
    trail.textContent = parts.filter(Boolean).join(' › ');
  }

  function prepare(shelf: HTMLElement): void {
    const panel = panelOf(shelf);
    if (!panel) {
      return;
    }
    // Set here rather than in PHP: without JS the shelf renders in place, and
    // a modal role there would hide the rest of the page from screen readers.
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    if (shelf.dataset.neoShelfTitle) {
      panel.setAttribute('aria-labelledby', shelf.dataset.neoShelfTitle);
    }
    panel.tabIndex = -1;
    buildTrail(shelf);
  }

  function animateIn(shelf: HTMLElement): void {
    if (reducedMotion()) {
      return;
    }
    const panel = panelOf(shelf);
    if (!panel) {
      return;
    }
    shelf.classList.add('is-entering');
    const done = (): void => {
      shelf.classList.remove('is-entering');
      panel.removeEventListener('animationend', onEnd);
    };
    // Animations of the fields inside bubble up to the panel too.
    const onEnd = (event: AnimationEvent): void => {
      if (event.target === panel) {
        done();
      }
    };
    panel.addEventListener('animationend', onEnd);
    window.setTimeout(done, 1000);
  }

  /**
   * Slides a shelf or a ghost out, then calls done.
   *
   * The slide is shorter than the slide in: the editor is finished with it.
   */
  function animateOut(shelf: HTMLElement, done: () => void): void {
    const panel = panelOf(shelf);
    let finished = false;
    const finish = (): void => {
      if (finished) {
        return;
      }
      finished = true;
      panel?.removeEventListener('animationend', onEnd);
      window.clearTimeout(timer);
      leaving.delete(shelf);
      shelf.classList.remove('is-leaving');
      done();
    };
    const onEnd = (event: AnimationEvent): void => {
      if (event.target === panel) {
        finish();
      }
    };
    const timer = window.setTimeout(finish, 600);
    panel?.addEventListener('animationend', onEnd);
    shelf.classList.remove('is-entering');
    shelf.classList.add('is-leaving');
    leaving.set(shelf, () => {
      finished = true;
      panel?.removeEventListener('animationend', onEnd);
      window.clearTimeout(timer);
      leaving.delete(shelf);
      shelf.classList.remove('is-leaving');
    });
  }

  /**
   * Copies an open shelf that an AJAX response is about to remove.
   *
   * The copy is appended to the body, where the selectors that size and dim a
   * nested shelf no longer reach it, so it takes its depth and backdrop from
   * the original. It holds no ids or names, so nothing finds a field or a
   * button in it, and it is inert.
   */
  function makeGhost(shelf: HTMLElement): void {
    const key = keyOf(shelf);
    if (ghosts.has(key) || reducedMotion()) {
      return;
    }
    const ghost = shelf.cloneNode(true) as HTMLElement;
    ghost.classList.add('is-ghost');
    ghost.classList.remove('is-entering');
    ghost.querySelectorAll<HTMLElement>('.neo-shelf').forEach((nested) => {
      nested.classList.add('is-ghost');
    });
    ghost.querySelectorAll('[id], [name]').forEach((el) => {
      el.removeAttribute('id');
      el.removeAttribute('name');
    });
    ghost.setAttribute('inert', '');
    ghost.setAttribute('aria-hidden', 'true');
    ghost.style.setProperty(
      '--neo-shelf-depth',
      getComputedStyle(shelf).getPropertyValue('--neo-shelf-depth'),
    );
    const backdrop = shelf.querySelector<HTMLElement>(':scope > .neo-shelf__backdrop');
    const ghostBackdrop = ghost.querySelector<HTMLElement>(':scope > .neo-shelf__backdrop');
    if (backdrop && ghostBackdrop) {
      ghostBackdrop.style.background = getComputedStyle(backdrop).background;
    }
    document.body.append(ghost);
    const body = part(shelf, 'body');
    const ghostBody = part(ghost, 'body');
    if (body && ghostBody) {
      ghostBody.scrollTop = body.scrollTop;
    }
    ghosts.set(key, ghost);
  }

  /**
   * Focuses a field of a shelf's own content, not of a shelf nested in it.
   */
  function focusInto(shelf: HTMLElement, preferInvalid: boolean): void {
    const body = part(shelf, 'body');
    const own = (el: HTMLElement) => nearestShelf(el) === shelf && isVisible(el);
    let target: HTMLElement | undefined;
    if (body && preferInvalid) {
      target = Array.from(body.querySelectorAll<HTMLElement>(
        '[aria-invalid="true"], input.error, select.error, textarea.error',
      )).find(own);
    }
    if (body && !target) {
      target = Array.from(body.querySelectorAll<HTMLElement>(FIELDS)).find(own);
    }
    (target || panelOf(shelf))?.focus();
  }

  /**
   * The button to focus once a shelf closed.
   */
  function findReturnTarget(key: string): HTMLElement | null {
    for (const name of returnTargets.get(key) || []) {
      const el = byName(name);
      if (el) {
        return el;
      }
    }
    // Nothing named survived, so the first button left in the owner.
    const ownerId = owners.get(key);
    const owner = ownerId ? document.getElementById(ownerId) : null;
    if (!owner) {
      return null;
    }
    return Array.from(owner.querySelectorAll<HTMLElement>(
      'button:not([disabled]), input[type="submit"]:not([disabled])',
    )).find((el) => isVisible(el) && !nearestShelf(el)) || null;
  }

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
    const shelves = Array.from(document.querySelectorAll<HTMLElement>(SHELF));
    const byKey = new Map<string, HTMLElement>();
    shelves.forEach((shelf) => byKey.set(keyOf(shelf), shelf));

    // Forget what closed, remembering where focus should go back to. A shelf
    // hidden by a tab that is not shown has not closed.
    let returnTo: HTMLElement | null = null;
    openKeys.forEach((key) => {
      const shelf = byKey.get(key);
      if (shelf && !isClosed(shelf)) {
        return;
      }
      returnTo = returnTo || findReturnTarget(key);
      openKeys.delete(key);
      returnTargets.delete(key);
      owners.delete(key);
      dirtyKeys.delete(key);
      openedAt.delete(key);
      if (shelf) {
        seen.delete(shelf);
      }
      emit(shelf || null, 'close', key);
    });

    // Take in what is new: newly opened, or re-rendered after an error.
    const opened: HTMLElement[] = [];
    const rerendered: HTMLElement[] = [];
    shelves.filter(isShown).forEach((shelf) => {
      if (seen.has(shelf)) {
        return;
      }
      seen.add(shelf);
      const key = keyOf(shelf);
      prepare(shelf);
      if (openKeys.has(key)) {
        rerendered.push(shelf);
        return;
      }
      openKeys.add(key);
      openedAt.set(key, Date.now());
      returnTargets.set(key, (shelf.dataset.neoShelfReturn || '')
        .split(' ')
        .filter(Boolean));
      if (shelf.dataset.neoShelfOwner) {
        owners.set(key, shelf.dataset.neoShelfOwner);
      }
      opened.push(shelf);
    });
    opened.forEach((shelf) => emit(shelf, 'open', keyOf(shelf)));

    // A ghost whose shelf came back, as one does after a failed Done, goes
    // at once. The rest slide out.
    ghosts.forEach((ghost, key) => {
      ghosts.delete(key);
      const shelf = byKey.get(key);
      if (shelf && isShown(shelf)) {
        ghost.remove();
      }
      else {
        animateOut(ghost, () => ghost.remove());
      }
    });

    const top = topShelf();
    document.documentElement.classList.toggle('neo-shelf-open', !!top);

    if (top && opened.includes(top)) {
      animateIn(top);
      focusInto(top, false);
      return;
    }
    if (top && rerendered.includes(top)) {
      focusInto(top, true);
      return;
    }
    const target = returnTo as HTMLElement | null;
    if (target && (!top || top.contains(target))) {
      target.focus();
      return;
    }
    // Focus went down with the element that had it.
    if (top && !panelOf(top)?.contains(document.activeElement)) {
      focusInto(top, false);
    }
  }

  function onEscape(event: KeyboardEvent): void {
    if (event.defaultPrevented || escOwnedElsewhere) {
      return;
    }
    const target = event.target as HTMLElement;
    // Editors and open dropdowns use Esc themselves.
    if (target.closest?.('.ck, .ck-body-wrapper')
      || target.getAttribute?.('aria-expanded') === 'true') {
      return;
    }
    const top = topShelf();
    if (api.escapeHandlers.some((handler) => handler(event, top))) {
      return;
    }
    if (top) {
      event.preventDefault();
      requestClose(top);
    }
  }

  function onEnter(event: KeyboardEvent): void {
    if (event.defaultPrevented || event.isComposing
      || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
      return;
    }
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) {
      return;
    }
    const shelf = nearestShelf(target);
    if (!shelf) {
      return;
    }
    if (TEXT_TYPES.includes(target.type)) {
      if (target.getAttribute('aria-expanded') === 'true') {
        return;
      }
      event.preventDefault();
      const primary = footerButton(shelf, 'data-neo-shelf-primary');
      if (primary) {
        press(primary);
      }
    }
    else if (target.type === 'checkbox' || target.type === 'radio') {
      // The browser would submit the form with its first button, which is
      // somewhere under the backdrop.
      event.preventDefault();
    }
  }

  function onTab(event: KeyboardEvent): void {
    const top = topShelf();
    const panel = top ? panelOf(top) : null;
    if (!panel || modalOpen() || !panel.contains(document.activeElement)) {
      return;
    }
    const items = Array.from(panel.querySelectorAll<HTMLElement>(FOCUSABLE))
      .filter(isVisible);
    if (!items.length) {
      return;
    }
    const first = items[0];
    const last = items[items.length - 1];
    if (event.shiftKey && (document.activeElement === first || document.activeElement === panel)) {
      event.preventDefault();
      last.focus();
    }
    else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  // Captured before anything else sees the key: a modal closing on Esc takes
  // itself off the stack before this key reaches the document.
  window.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      escOwnedElsewhere = modalOpen();
    }
  }, true);

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      onEscape(event);
    }
    else if (event.key === 'Enter') {
      onEnter(event);
    }
    else if (event.key === 'Tab') {
      onTab(event);
    }
  });

  document.addEventListener('focusin', (event) => {
    const top = topShelf();
    const target = event.target as HTMLElement;
    if (!top || panelOf(top)?.contains(target) || modalOpen()) {
      return;
    }
    if (target.closest?.(FOCUS_ALLOWED)) {
      return;
    }
    focusInto(top, false);
  });

  document.addEventListener('pointerdown', (event) => {
    pointerDownTarget = event.target;
  }, true);

  document.addEventListener('click', (event) => {
    const dismiss = event.target instanceof Element
      ? event.target.closest<HTMLElement>('[data-neo-shelf-dismiss]')
      : null;
    if (!dismiss) {
      return;
    }
    // A drag that started inside the panel and ended on the backdrop, like a
    // text selection, is not a click on the backdrop.
    if (dismiss.dataset.neoShelfDismiss === 'backdrop' && pointerDownTarget !== dismiss) {
      return;
    }
    const shelf = nearestShelf(dismiss);
    if (shelf) {
      event.preventDefault();
      requestClose(shelf);
    }
  });

  const markDirty = (event: Event): void => {
    if (!event.isTrusted) {
      return;
    }
    const shelf = nearestShelf(event.target);
    if (shelf) {
      dirtyKeys.add(keyOf(shelf));
    }
  };
  document.addEventListener('input', markDirty, true);
  document.addEventListener('change', markDirty, true);

  // Editors report their changes this way only.
  $(document).on('formUpdated.neoShelf', (event) => {
    const shelf = nearestShelf(event.target as Node);
    if (!shelf) {
      return;
    }
    const key = keyOf(shelf);
    if (Date.now() - (openedAt.get(key) || 0) > 500) {
      dirtyKeys.add(key);
    }
  });

  const api = {
    /**
     * Reasons beyond changed fields that closing a shelf loses work.
     */
    dirtyChecks: [] as Array<(shelf: HTMLElement) => boolean>,
    /**
     * Esc handlers that run before the top shelf is asked to close. One that
     * handles the key returns true, and prevents its default itself.
     */
    escapeHandlers: [] as Array<(event: KeyboardEvent, top: HTMLElement | null) => boolean>,
    /**
     * Selectors of AJAX wrappers inside a shelf whose updates are not changes
     * to it, such as a nested widget that applies its own work.
     */
    quietAjax: [] as string[],
    open(shelf: HTMLElement): void {
      leaving.get(shelf)?.();
      shelf.classList.remove('is-closed');
      scheduleSync();
    },
    close(shelf: HTMLElement): void {
      if (isClosed(shelf)) {
        return;
      }
      if (reducedMotion() || !isVisible(shelf)) {
        shelf.classList.add('is-closed');
      }
      else {
        // Closed for the page at once, so focus goes back while it slides.
        animateOut(shelf, () => shelf.classList.add('is-closed'));
      }
      scheduleSync();
    },
    requestClose,
    isDirty,
    markDirty(shelf: HTMLElement): void {
      dirtyKeys.add(keyOf(shelf));
    },
    nearest: nearestShelf,
    top: topShelf,
    part,
    press,
    isVisible,
    sync: scheduleSync,
  };
  (Drupal as unknown as { neoShelf: typeof api }).neoShelf = api;

  Drupal.behaviors.neoShelf = {
    attach(context?: HTMLElement): void {
      // Something inside an open shelf was replaced over AJAX, such as a media
      // field after choosing an image.
      if (context instanceof HTMLElement && !context.matches(SHELF)
        && !api.quietAjax.some((selector) => context.matches(selector))) {
        const shelf = nearestShelf(context);
        if (shelf && seen.has(shelf)) {
          dirtyKeys.add(keyOf(shelf));
        }
      }
      once('neo-shelf', SHELF, context)
        .forEach((el) => observer?.observe(el));
      scheduleSync();
    },
    detach(context?: HTMLElement, _settings?: unknown, trigger?: string): void {
      // An AJAX response is about to replace this: copy the open shelves it
      // holds, outermost only, since a copy carries what is nested in it.
      if (trigger === 'unload' && context instanceof HTMLElement) {
        const open = [
          ...(context.matches(SHELF) ? [context] : []),
          ...Array.from(context.querySelectorAll<HTMLElement>(SHELF)),
        ].filter((shelf) => openKeys.has(keyOf(shelf)) && isShown(shelf));
        open.filter((shelf) => !open.some((other) => other !== shelf && other.contains(shelf)))
          .forEach(makeGhost);
      }
      if (context && observer) {
        context.querySelectorAll?.(SHELF)
          .forEach((el) => observer.unobserve(el));
      }
      scheduleSync();
    },
  };

})(jQuery, Drupal, once);
