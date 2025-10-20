(function (Drupal, once, Popper) {

  const baseSettings = {
    dropdownParent: document.body,
    maxOptions: null,
    wrapperClass: 'ts-wrapper overflow-hidden p-0!',
    controlClass: 'ts-control form-item-bg flex items-center gap-2 border-none focus:border-none focus:outline-none py-2 px-3',
    itemClass: 'item block form-item-content whitespace-nowrap',
    dropdownClass: 'ts-dropdown form--neo form-item-border bg-form-item-base overflow-hidden z-100 shadow-xl',
	  optionClass: 'option py-2 leading-none is-active:bg-primary-500 is-active:text-primary-500-content',
    onInitialize: function() {
      const instance = this as any;
      const el = instance.input;
      const control = instance.control as HTMLElement;
      if (el.classList.contains('use-neo-tooltip') && Drupal.behaviors.neoTooltip) {
        control.classList.add('use-neo-tooltip');
        for (const key in el.dataset) {
          if (key.startsWith('tippy')) {
            control.dataset[key] = el.dataset[key];
          }
        }
        Drupal.behaviors.neoTooltip.attach(instance.wrapper);
      }

      // Will un every 250ms to check if the dropdown is open and update the
      // width of the dropdown.
      instance.dropdownWatch = null;
      instance.dropdownWatchCb = () => {
        const rect = instance.wrapper.getBoundingClientRect();
        instance.dropdown.style.width = Math.max(rect.width, 140) + 'px';
        setTimeout(() => {
          instance.popper.update();
        }, 0);
      }
    },
    onDropdownOpen: function() {
      const instance = this as any;
      document.querySelector('body')?.classList.add('ts-open');
      const scheme = closestSchemeClass(instance.wrapper);
      if (scheme) {
        instance.dropdown.classList.add(scheme.schemeClass);
      }

      instance.dropdownWatchCb();
      instance.dropdownWatch = setInterval(instance.dropdownWatchCb, 250);
      // Utilize Popper.js to position the dropdown.
      instance.popper = Popper.createPopper(instance.wrapper, instance.dropdown, {
        placement: 'bottom-start',
        modifiers: [
          {
            name: 'flip',
            options: {
              fallbackPlacements: ['top-start', 'bottom-start'],
            },
          },
          {
            name: 'preventOverflow',
            options: {
              boundary: 'viewport',
              padding: 4,
            },
          },
        ],
      });
    },
    onDropdownClose: function() {
      const instance = this as any;
      document.querySelector('body')?.classList.remove('ts-open');
      clearInterval(instance.dropdownWatch);
      instance.dropdown.classList.remove('is-open');
      instance.popper.destroy();
      instance.popper = null
    },
    onFocus: function() {
      if (Drupal.behaviors.neoTooltip) {
        Drupal.behaviors.neoTooltip.disableAll();
      }
    },
    onBlur: function() {
      if (Drupal.behaviors.neoTooltip) {
        Drupal.behaviors.neoTooltip.enableAll();
      }
    },
    render: {},
  };

  function closestSchemeClass(el: Element | null): { element: Element, schemeClass: string } | null {
    while (el) {
      const classList = Array.from(el.classList); // <-- fixes TS warning
      for (const cls of classList) {
        if (cls.startsWith('scheme-')) {
          return { element: el, schemeClass: cls };
        }
      }
      el = el.parentElement;
    }
    return null;
  }

  function findEmptyOption(selectElement: HTMLSelectElement): HTMLOptionElement | null {
    const options = selectElement.options;

    for (let i = 0; i < options.length; i++) {
      const option = options[i];
      if (option.value === "") {
        return option;
      }
    }

    return null; // No empty option found
  }

  Drupal.behaviors.neoTomSelect = {
    attach: () => {

      once('neo.tom', 'select.neo-select').forEach((el) => {
        if (el instanceof HTMLSelectElement) {
          var observer = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
              if (entry.intersectionRatio > 0) {
                observer.disconnect();
                const parent = el.closest('.form--item') as HTMLElement;
                if (parent) {
                  parent.classList.add('neo-tom-wrapper');
                  parent.classList.add('neo-tom-select-wrapper');
                }
                let settings = {
                  allowEmptyOption: findEmptyOption(el) !== null,
                  selectOnTab: true,
                  plugins: {
                    dropdown_input: {},
                  },
                  placeholder: 'Search...',
                } as any;
                if (el.multiple) {
                  parent.classList.add('neo-tom-multiple');
                  settings = {...settings, ...{
                    maxOptions: null,
                    plugins: {
                      drag_drop: {},
                      remove_button: {
                        title:'Remove this item',
                      }
                    }
                  }};
                }
                const finalSettings = {...settings, ...baseSettings};
                finalSettings.render.item = function(data:any, escape:Function) {
                  return '<div>' + escape(data.text) + '</div>';
                };
                finalSettings.render.option = function(data:any, escape:Function) {
                  return '<div>' + escape(data.text) + '</div>';
                };
                const control = new TomSelect(el, finalSettings);
                el.addEventListener('change', function () {
                  let newValue:string | string[] = el.value;
                  if (el.multiple) {
                    newValue = [];
                    for (const option of el.options) {
                        if (option.selected) {
                            newValue.push(option.value);
                        }
                    }
                  }
                  if (control.getValue() !== newValue) {
                    control.setValue(newValue, true);
                  }
                });
                if (el.multiple) {
                  control.removeOption('_none');
                }
              }
            });
          });
          observer.observe(el);
        }
      });

      once('neo.tom', 'input.neo-entity-autocomplete').forEach(el => {
        if (!(el instanceof HTMLInputElement)) {
          return;
        }
        var observer = new IntersectionObserver((entries, observer) => {
          entries.forEach(entry => {
            if (entry.intersectionRatio > 0) {
              observer.disconnect();
              const parent = el.parentElement;
              const multiple = el.classList.contains('neo-multi-select');
              const autocreate = el.classList.contains('neo-autocreate');
              if (parent) {
                parent.classList.add('neo-tom-wrapper');
              }
              let settings = {
                valueField: 'value',
                labelField: 'label',
                searchField: [],
                create: autocreate,
                createOnBlur: autocreate,
                dropdownParent: document.body,
                maxItems: 1,
                load: function(query:any, callback:any) {
                  const path = el.dataset.autocompletePath as string;
                  const url = path + (path.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(query);
                  this.clearOptions();
                  fetch(url)
                    .then(response => response.json())
                    .then(json => {
                      console.log(json);
                      callback(json);
                    }).catch(()=>{
                      callback();
                    });
                },
                plugins: {},
              } as any;
              const blacklist = el.dataset.autocompleteFirstCharacterBlacklist || false;
              if (blacklist) {
                settings.shouldLoad = function(query:any) {
                  if (query.length > 0 && blacklist.includes(query[0])) {
                    return false;
                  }
                  return true;
                }
              }
              if (multiple) {
                settings = {...settings, ...{
                  maxItems: null,
                  onItemAdd: function() {
                    const select = this as any;
                    select.setTextboxValue('');
                    select.refreshOptions();
                  },
                  plugins: {
                    drag_drop: {},
                    remove_button: {
                      title:'Remove this item',
                    }
                  }
                }};
              }
              else {
                settings.plugins.clear_button = {};
              }
              const finalSettings = {...settings, ...baseSettings};
              finalSettings.render.item = function(data:any, escape:Function) {
                return '<div>' + escape(data[finalSettings.valueField]) + '</div>';
              };
              finalSettings.render.option = function(data:any, escape:Function) {
                // Allow HTML in the option text.
                let label = data[finalSettings.labelField] || '';
                if (data.option) {
                  label = data.option;
                  // Remove all script tags and their content
                  let sanitized = label.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '');
                  // Remove event handlers
                  sanitized = sanitized.replace(/\s*on\w+\s*=\s*["'][^"']*["']/gi, '');
                  // Remove javascript: URLs
                  sanitized = sanitized.replace(/javascript:/gi, '');
                  // Remove data: URLs (can contain JS)
                  sanitized = sanitized.replace(/data:/gi, '');
                return '<div>' + sanitized + '</div>';
                }
                return '<div>' + escape(label) + '</div>';
              };
              finalSettings.render.option_create = function(data:any, escape:any) {
                if (autocreate) {
                  return '<div class="create">Create <strong>' + escape(data.input) + '</strong>&hellip;</div>';
                }
                return null;
              }
              const control = new TomSelect(el, finalSettings);
              control.on('change', function (_value:any) {
                // Trigger autocomplete close event.
                const autocompleteCloseEvent = new CustomEvent('autocompleteclose', {
                  bubbles: true,
                  cancelable: true,
                  detail: {} // You can add custom data here if needed
                });
                // Dispatch the event
                control.input.dispatchEvent(autocompleteCloseEvent);

                // Trigger an input event on the form to notify that the value
                // has changed.
                const form = control.input.closest('form');
                if (form) {
                  const inputEvent = new InputEvent('input', {
                    bubbles: true,
                    cancelable: true,
                  });
                  form.dispatchEvent(inputEvent);
                }
              });

            }
          });
        });
        observer.observe(el);
      });
    }
  };

})(Drupal, once, Popper);
