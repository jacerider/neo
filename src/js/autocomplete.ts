(function ($, Drupal, once, Popper) {
  'use strict';

  Drupal.behaviors.autocompletePopper = {
    attach(context: HTMLElement | undefined): void {
      once('neo-autocomplete', '[data-autocomplete-path]', context).forEach((input) => {
        console.log('autocomplete', input);
        const $input: JQuery = $(input);
        const originalAutocomplete: JQueryAutocompleteInstance | undefined = $input.autocomplete('instance');
        if (!originalAutocomplete) {
          return;
        }
        if (originalAutocomplete) {
          // Override the position option
          const positionConfig: JQueryUIPosition = {
            my: 'left top',
            at: 'left bottom',
            using(this: HTMLUListElement): void {
              const menu: HTMLElement = this;
              const $menu: JQuery = $(menu);
              const menuElement: HTMLElement = $menu[0];

              // Destroy existing popper instance if it exists
              const existingPopper = $menu.data('popper');
              if (existingPopper) {
                existingPopper.destroy();
              }

              // Get input width
              const inputWidth = Math.max(input.getBoundingClientRect().width, 200);

              // Set initial styles including width
              $menu.css({
                position: 'absolute',
                top: 0,
                left: 0,
                zIndex: 9999,
                width: `${inputWidth}px`, // Match input width
                minWidth: `${inputWidth}px`, // Prevent shrinking
              });

              // Create Popper instance
              const popperInstance = Popper.createPopper(input, menuElement, {
                placement: 'bottom-start',
                modifiers: [
                  {
                    name: 'offset',
                    options: {
                      offset: [0, 4],
                    },
                  },
                  {
                    name: 'flip',
                    options: {
                      fallbackPlacements: ['top-start', 'bottom-start'],
                    },
                  },
                  {
                    name: 'preventOverflow',
                    options: {
                      boundary: 'viewport' as const,
                      padding: 8,
                    },
                  },
                ],
              });

              // Store popper instance for cleanup
              $menu.data('popper', popperInstance);

              // Make menu visible
              $menu.css('position', 'absolute');
            }
          };

          $input.autocomplete('option', 'position', positionConfig);

          // Cleanup on close
          $input.on('autocompleteclose', function (this: HTMLInputElement): void {
            const $menu: JQuery | undefined = $(this).autocomplete('widget');
            if (!$menu) {
              return;
            }
            const popperInstance = $menu.data('popper');

            if (popperInstance) {
              popperInstance.destroy();
              $menu.removeData('popper');
            }
          });
        }
      });
    }
  };

})(jQuery, Drupal, once, Popper);
