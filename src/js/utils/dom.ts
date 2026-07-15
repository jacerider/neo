export function wrapElement(elem: HTMLElement, wrapper: HTMLElement): HTMLElement {
  if (elem.parentElement === null) {
    throw Error('`elem` has no parentElement');
  }

  elem.parentElement.insertBefore(wrapper, elem);
  wrapper.appendChild(elem);

  return elem;
}

export function unwrapElement(elem: HTMLElement): void {
  const parent = elem.parentElement;

  if (parent === null) {
    throw Error('`elem` has no parentElement');
  }

  while (elem.firstChild) {
    parent.insertBefore(elem.firstChild, elem);
  }

  parent.removeChild(elem);
}

export function parents(elem: Node | null, selector: string, limit?: number): HTMLElement[] {
  const matched: HTMLElement[] = [];

  while (
    elem &&
    elem.parentElement !== null &&
    (limit === undefined ? true : matched.length < limit)
  ) {
    if (elem instanceof HTMLElement && elem.matches(selector)) {
      matched.push(elem);
    }

    elem = elem.parentElement;
  }

  return matched;
}

export function parentsOne(elem: Node, selector: string): HTMLElement | null {
  const matches = parents(elem, selector, 1);

  return matches.length ? matches[0] : null;
}

export function getParentsUntil(element: Element, selector: string, finalElement: HTMLElement): Element[] {
  const parents: Element[] = [];
  let currentElement: Element | null = element.parentElement;

  while (currentElement) {
    if (currentElement.matches(selector)) {
      parents.push(currentElement);
    }
    if (currentElement === finalElement) {
      break;
    }
    currentElement = currentElement.parentElement;
  }

  return parents;
}

/**
 * Wraps non-ul children of li elements in a div
 * Works recursively through nested ul/li structures
 */
export function wrapLiContents(rootElement: HTMLElement, className?: string, levelClassPrefix?: string): void {
  // Find all li elements in the current context
  const listItems = rootElement.querySelectorAll('li');

  listItems.forEach(li => {
    // Create a div to hold the non-ul content
    const contentDiv = document.createElement('div');
    if (className) {
      contentDiv.classList.add(className);
    }

    if (levelClassPrefix) {
      const parents = getParentsUntil(li, 'ul', rootElement);
      li.classList.add(`${levelClassPrefix}-${parents.length}`);
    }

    // Store all child nodes
    const childNodes = Array.from(li.childNodes);

    // Array to hold ul elements that will be appended after the div
    const ulElements: Element[] = [];

    // Process each child node
    childNodes.forEach(node => {
      // Check if this is a submenu ul element. Inline (expanded) lists are
      // ordinary content of the current panel and stay inside the wrapper so
      // they animate with the row.
      if (node.nodeType === Node.ELEMENT_NODE &&
          (node as Element).tagName.toLowerCase() === 'ul' &&
          !(node as Element).classList.contains('neo-slide-menu--inline')) {
        // Store ul elements to reattach later
        ulElements.push(node as Element);
      } else {
        // Move non-ul content to the div
        contentDiv.appendChild(node.cloneNode(true));
      }
    });

    // Clear the li content
    li.innerHTML = '';

    // Add the div with non-ul content back
    if (contentDiv.hasChildNodes()) {
      li.appendChild(contentDiv);
    }

    // Re-append the ul elements after the div
    ulElements.forEach(ul => {
      li.appendChild(ul);

      // Recursively process this ul's children
      wrapLiContents(ul as HTMLElement);
    });
  });
}
