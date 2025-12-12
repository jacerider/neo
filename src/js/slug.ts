(function (Drupal, once) {

  let override: boolean = false;

  Drupal.behaviors.neoSlug = {
    attach: () => {
      once('neo.slug', 'input.neo-slug').forEach((el) => {
        const input = el as HTMLInputElement;
        const sourceId = el.getAttribute('data-neo-slug-source');
        if (!input.value && sourceId) {
          const sourceEl = document.getElementById(sourceId) as HTMLInputElement;
          if (sourceEl) {
            transformSlugValue(input, sourceEl);
            sourceEl.addEventListener('input', () => {
              if (!override) {
                transformSlugValue(input, sourceEl);
              }
            });
          }
        }
        transformSlugValue(input);
        input.addEventListener('input', handleSlugInput);
      });
    }
  };

  function handleSlugInput(event: Event): void {
    override = true;
    const input = event.target as HTMLInputElement;
    const cursorPosition = input.selectionStart;

    transformSlugValue(input);

    // Restore cursor position
    if (cursorPosition !== null) {
      input.setSelectionRange(cursorPosition, cursorPosition);
    }
  }

  function transformSlugValue(input: HTMLInputElement, source: HTMLInputElement|null = null): void {
    const from = source || input;
    const transformedValue = from.value
      .toLowerCase()
      .replace(/\s+/g, '-')
      .replace(/[^a-z-]/g, '')
      .replace(/-+/g, '-')
      .replace(/[-/]+$/, '');

    input.value = transformedValue;
  }

})(Drupal, once);
