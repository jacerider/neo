/**
 * Neo Inline Entity Form shelves.
 *
 * The widget renders each open inline entity form as a shelf: a container,
 * left where IEF put it inside the form, that CSS fixes over the page as a
 * side panel. This behavior makes the shelf behave like a dialog. It adds the
 * dialog semantics, the trail of shelves it sits in, focus handling, Esc and
 * Enter, and the question before unsaved changes are thrown away.
 *
 * Every IEF button is an AJAX button that replaces the whole widget, so a
 * shelf is rebuilt from scratch on each round trip. The state here is kept
 * per shelf key (widget id, operation and row), which survives that, and
 * everything is resolved again in sync() after each attach and detach.
 */
(function ($, Drupal, once): void {

  const SHELF = '.neo-ief-shelf';
  const CONFIRM = '[data-neo-ief-confirm]';
  const WRAPPER = '[id^="inline-entity-form-"]';

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

  // Surfaces (shelves and remove confirmations) known to be open, by key.
  const openKeys = new Set<string>();
  // Button names to focus once a surface closes, in order of preference.
  const returnTargets = new Map<string, string[]>();
  // Shelves whose fields changed since they opened.
  const dirtyKeys = new Set<string>();
  // When each shelf opened, to ignore the change events editors fire while
  // they start up.
  const openedAt = new Map<string, number>();
  // Surface elements already handled. A re-rendered surface is a new element
  // with a known key.
  const seen = new WeakSet<HTMLElement>();

  let syncFrame = 0;
  let escOwnedElsewhere = false;
  let pointerDownTarget: EventTarget | null = null;

  // A shelf inside a field group tab that is not shown is not open as far as
  // the page is concerned. Watching visibility catches the tab switch.
  const observer = 'IntersectionObserver' in window
    ? new IntersectionObserver(() => scheduleSync())
    : null;

  function keyOf(surface: HTMLElement): string {
    if (surface.matches(SHELF)) {
      return surface.dataset.neoIefShelf || '';
    }
    return 'confirm|' + (surface.dataset.neoIefConfirm || '');
  }

  function isVisible(el: HTMLElement): boolean {
    return typeof el.checkVisibility === 'function'
      ? el.checkVisibility()
      : el.getClientRects().length > 0;
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
      .filter(isVisible);
  }

  function topShelf(): HTMLElement | null {
    const shelves = activeShelves();
    return shelves.length ? shelves[shelves.length - 1] : null;
  }

  function part(shelf: HTMLElement, name: string): HTMLElement | null {
    return shelf.querySelector<HTMLElement>(
      ':scope > .neo-ief-shelf__panel > .neo-ief-shelf__' + name,
    );
  }

  function panelOf(shelf: HTMLElement): HTMLElement | null {
    return shelf.querySelector<HTMLElement>(':scope > .neo-ief-shelf__panel');
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

  /**
   * Presses an IEF button.
   *
   * Drupal binds AJAX buttons to mousedown and cancels their click, so a
   * click() would do nothing. A button Drupal disabled is mid-request.
   */
  function press(button: HTMLElement): void {
    if ((button as HTMLButtonElement).disabled) {
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
    // Entries added inside a new entity are dropped with it. In an edit
    // form they are kept, as IEF keeps them, so there is nothing to lose.
    const op = shelf.dataset.neoIefOp;
    if (op === 'add' || op === 'duplicate') {
      return Array.from(body.querySelectorAll('[data-neo-ief-pending="true"]'))
        .some(own);
    }
    return false;
  }

  /**
   * Closes a shelf through its Cancel button, asking first if needed.
   */
  function requestClose(shelf: HTMLElement): void {
    const cancel = footerButton(shelf, 'data-neo-ief-cancel');
    if (!cancel) {
      return;
    }
    if (isDirty(shelf) && !window.confirm(Drupal.t('Discard your changes to this @noun?', {
      '@noun': shelf.dataset.neoIefNoun || Drupal.t('item'),
    }))) {
      return;
    }
    press(cancel);
  }

  /**
   * The open remove confirmation that belongs to a shelf, or to the page.
   */
  function openConfirm(shelf: HTMLElement | null): HTMLElement | null {
    const scope: ParentNode = shelf ? part(shelf, 'body') || shelf : document;
    const confirms = Array.from(scope.querySelectorAll<HTMLElement>(CONFIRM))
      .filter((el) => nearestShelf(el) === shelf && isVisible(el));
    return confirms.length ? confirms[confirms.length - 1] : null;
  }

  function buildTrail(shelf: HTMLElement): void {
    const trail = part(shelf, 'header')
      ?.querySelector<HTMLElement>('.neo-ief-shelf__trail');
    if (!trail) {
      return;
    }
    const chain: HTMLElement[] = [];
    for (let ancestor = parentShelf(shelf); ancestor; ancestor = parentShelf(ancestor)) {
      chain.unshift(ancestor);
    }
    const parts: string[] = [];
    chain.forEach((ancestor) => {
      parts.push(ancestor.dataset.neoIefCollection || '');
      parts.push(ancestor.dataset.neoIefLabel || '');
    });
    parts.push(shelf.dataset.neoIefCollection || '');
    trail.textContent = parts.filter(Boolean).join(' › ');
  }

  function prepare(shelf: HTMLElement): void {
    const panel = panelOf(shelf);
    if (!panel) {
      return;
    }
    // Set here rather than in PHP: without JS the form renders inline, and a
    // modal role there would hide the rest of the page from screen readers.
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    if (shelf.dataset.neoIefTitle) {
      panel.setAttribute('aria-labelledby', shelf.dataset.neoIefTitle);
    }
    panel.tabIndex = -1;
    buildTrail(shelf);
  }

  function animateIn(shelf: HTMLElement): void {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
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
   * Focuses a field of a shelf's own form, not of a shelf nested in it.
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
        const shelves = Array.from(wrapper.querySelectorAll<HTMLElement>(SHELF))
          .filter(isVisible);
        const shelf = shelves[shelves.length - 1];
        const body = shelf ? part(shelf, 'body') : null;
        if (body) {
          body.prepend(messages);
        }
      });
  }

  /**
   * The button to focus once a surface closed.
   */
  function findReturnTarget(key: string): HTMLElement | null {
    for (const name of returnTargets.get(key) || []) {
      const el = byName(name);
      if (el) {
        return el;
      }
    }
    // Nothing named survived, so the first button left in the widget.
    const iefId = key.startsWith('confirm|') ? key.split('|')[1] : key.split('|')[0];
    const wrapper = document.getElementById('inline-entity-form-' + iefId);
    if (!wrapper) {
      return null;
    }
    return Array.from(wrapper.querySelectorAll<HTMLElement>(
      'button:not([disabled]), input[type="submit"]:not([disabled])',
    )).find(isVisible) || null;
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
    moveMessages();

    const surfaces = Array.from(
      document.querySelectorAll<HTMLElement>(`${SHELF}, ${CONFIRM}`),
    );
    const present = new Set(surfaces.map(keyOf));

    // Forget what closed, remembering where focus should go back to.
    let returnTo: HTMLElement | null = null;
    openKeys.forEach((key) => {
      if (present.has(key)) {
        return;
      }
      returnTo = returnTo || findReturnTarget(key);
      openKeys.delete(key);
      returnTargets.delete(key);
      dirtyKeys.delete(key);
      openedAt.delete(key);
    });

    // Take in what is new: newly opened, or re-rendered after an error.
    const opened: HTMLElement[] = [];
    const rerendered: HTMLElement[] = [];
    surfaces.filter(isVisible).forEach((surface) => {
      if (seen.has(surface)) {
        return;
      }
      seen.add(surface);
      const key = keyOf(surface);
      if (surface.matches(SHELF)) {
        prepare(surface);
      }
      if (openKeys.has(key)) {
        rerendered.push(surface);
        return;
      }
      openKeys.add(key);
      openedAt.set(key, Date.now());
      returnTargets.set(key, (surface.dataset.neoIefReturn || '')
        .split(' ')
        .filter(Boolean));
      opened.push(surface);
    });

    const top = topShelf();
    document.documentElement.classList.toggle('neo-ief-shelf-open', !!top);

    if (top && opened.includes(top)) {
      animateIn(top);
      focusInto(top, false);
      return;
    }
    if (top && rerendered.includes(top)) {
      focusInto(top, true);
      return;
    }
    const confirm = openConfirm(top);
    if (confirm && opened.includes(confirm)) {
      confirm.querySelector<HTMLElement>('[data-neo-ief-cancel]')?.focus();
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
    const confirm = openConfirm(top);
    if (confirm && (top || confirm.contains(target))) {
      const cancel = confirm.querySelector<HTMLElement>('[data-neo-ief-cancel]');
      if (cancel) {
        event.preventDefault();
        press(cancel);
      }
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
      const primary = footerButton(shelf, 'data-neo-ief-primary');
      if (primary) {
        press(primary);
      }
    }
    else if (target.type === 'checkbox' || target.type === 'radio') {
      // The browser would submit the form with its first button, which is an
      // IEF button somewhere under the backdrop.
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
      ? event.target.closest<HTMLElement>('[data-neo-ief-shelf-dismiss]')
      : null;
    if (!dismiss) {
      return;
    }
    // A drag that started inside the panel and ended on the backdrop, like a
    // text selection, is not a click on the backdrop.
    if (dismiss.dataset.neoIefShelfDismiss === 'backdrop' && pointerDownTarget !== dismiss) {
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
  $(document).on('formUpdated.neoIefShelf', (event) => {
    const shelf = nearestShelf(event.target as Node);
    if (!shelf) {
      return;
    }
    const key = keyOf(shelf);
    if (Date.now() - (openedAt.get(key) || 0) > 500) {
      dirtyKeys.add(key);
    }
  });

  Drupal.behaviors.neoInlineEntityFormShelf = {
    attach(context?: HTMLElement): void {
      // Something inside an open shelf was replaced over AJAX, such as a media
      // field after choosing an image. A nested IEF widget is left out: what
      // it applies survives this shelf's Cancel.
      if (context instanceof HTMLElement
        && !context.matches(WRAPPER)
        && !context.matches(SHELF)) {
        const shelf = nearestShelf(context);
        if (shelf && seen.has(shelf)) {
          dirtyKeys.add(keyOf(shelf));
        }
      }
      once('neo-ief-surface', `${SHELF}, ${CONFIRM}`, context)
        .forEach((el) => observer?.observe(el));
      scheduleSync();
    },
    detach(context?: HTMLElement): void {
      if (context && observer) {
        context.querySelectorAll?.(`${SHELF}, ${CONFIRM}`)
          .forEach((el) => observer.unobserve(el));
      }
      scheduleSync();
    },
  };

})(jQuery, Drupal, once);
