/**
 * Neo | Meta tags - Side panel widget.
 *
 * Once the meta tags form is built, its shelf stays on the page and opens and
 * closes here, without a round trip (see \Drupal\neo\Shelf, "closed shelf").
 * Done closes it and keeps the edits, which are saved with the entity. Cancel,
 * and Esc or the backdrop through neo/shelf, puts back what the fields held
 * when the shelf opened. A shelf closed with changes marks the summary
 * Unsaved.
 */
(function (Drupal): void {

  interface NeoShelf {
    open: (shelf: HTMLElement) => void;
    close: (shelf: HTMLElement) => void;
    isDirty: (shelf: HTMLElement) => boolean;
  }
  const api = (Drupal as unknown as { neoShelf: NeoShelf }).neoShelf;

  type Field = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;
  type Value = string | boolean | string[];

  const SHELF = '.neo-metatag__shelf';
  const FIELDS = 'input:not([type="button"]):not([type="submit"]), select, textarea';

  // What each shelf's fields held when it opened.
  const snapshots = new WeakMap<HTMLElement, Map<Field, Value>>();

  function read(field: Field): Value {
    if (field instanceof HTMLInputElement && (field.type === 'checkbox' || field.type === 'radio')) {
      return field.checked;
    }
    if (field instanceof HTMLSelectElement && field.multiple) {
      return Array.from(field.selectedOptions).map((option) => option.value);
    }
    return field.value;
  }

  function write(field: Field, value: Value): void {
    if (typeof value === 'boolean') {
      (field as HTMLInputElement).checked = value;
    }
    else if (Array.isArray(value)) {
      Array.from((field as HTMLSelectElement).options).forEach((option) => {
        option.selected = value.includes(option.value);
      });
    }
    else {
      field.value = value;
    }
  }

  function take(shelf: HTMLElement): Map<Field, Value> {
    const snapshot = new Map<Field, Value>();
    shelf.querySelectorAll<Field>(FIELDS).forEach((field) => {
      snapshot.set(field, read(field));
    });
    return snapshot;
  }

  function restore(shelf: HTMLElement): void {
    snapshots.get(shelf)?.forEach((value, field) => {
      if (field.isConnected) {
        write(field, value);
      }
    });
  }

  document.addEventListener('neo-shelf:open', (event) => {
    const shelf = event.target;
    if (shelf instanceof HTMLElement && shelf.matches(SHELF)) {
      snapshots.set(shelf, take(shelf));
    }
  });

  // Delegated: an AJAX build replaces the widget element itself, which a
  // behavior's once() would not find inside its context.
  document.addEventListener('click', (event) => {
    const button = event.target instanceof Element
      ? event.target.closest<HTMLElement>('[data-neo-metatag-open], [data-neo-metatag-done], [data-neo-metatag-cancel]')
      : null;
    const widget = button?.closest<HTMLElement>('.neo-metatag__widget');
    const shelf = widget?.querySelector<HTMLElement>(SHELF);
    if (!button || !widget || !shelf) {
      return;
    }
    event.preventDefault();
    if (button.dataset.neoMetatagOpen) {
      api.open(shelf);
    }
    else if (button.dataset.neoMetatagDone) {
      if (api.isDirty(shelf)) {
        widget.querySelector<HTMLElement>('.neo-metatag__badge')?.removeAttribute('hidden');
      }
      api.close(shelf);
    }
    else {
      restore(shelf);
      api.close(shelf);
    }
  });

})(Drupal);
