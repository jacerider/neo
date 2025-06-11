(function (Drupal, once, Popper) {

  const baseSettings = {
    dropdownParent: document.body,
    maxOptions: null,
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
        if (instance.isOpen) {
          instance.popper.update();
          const rect = instance.wrapper.getBoundingClientRect();
          instance.dropdown.style.width = Math.max(rect.width, 140) + 'px';
        }
      }

      // Use pooper.js to position the dropdown.
      instance.popper = Popper.createPopper(instance.wrapper, instance.dropdown, {
        modifiers: [
          {
            name: 'preventOverflow',
            options: {
              boundary: instance.wrapper,
            },
          },
        ],
      });
    },
    onDropdownOpen: function() {
      const instance = this as any;
      instance.dropdownWatchCb();
      instance.dropdownWatch = setInterval(instance.dropdownWatchCb, 250);
    },
    onDropdownClose: function() {
      const instance = this as any;
      clearInterval(instance.dropdownWatch);
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
    render: {
      dropdown: function(){
        return '<div class="neo-tom-dropdown"></div>';
      },
    },
  };

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
                  settings = {...settings, ...{
                    maxOptions: null,
                    plugins: {
                      remove_button: {
                        title:'Remove this item',
                      }
                    }
                  }};
                }
                const control = new TomSelect(el, {...settings, ...baseSettings});
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

        var observer = new IntersectionObserver((entries, observer) => {
          entries.forEach(entry => {
            if (entry.intersectionRatio > 0) {
              observer.disconnect();
              const parent = el.parentElement;
              const multiple = el.classList.contains('neo-multi-select');
              if (parent) {
                parent.classList.add('neo-tom-wrapper');
              }
              let settings = {
                valueField: 'value',
                labelField: 'value',
                searchField: 'label',
                create: true,
                createOnBlur: true,
                dropdownParent: document.body,
                maxItems: 1,
                load: function(query:any, callback:any) {
                  const path = el.dataset.autocompletePath as string;
                  const url = path + (path.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(query);
                  fetch(url)
                    .then(response => response.json())
                    .then(json => {
                      callback(json);
                    }).catch(()=>{
                      callback();
                    });
                }
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
                    remove_button: {
                      title:'Remove this item',
                    }
                  }
                }};
              }
              const finalSettings = {...settings, ...baseSettings};
              finalSettings.render.option_create = function(data:any, escape:any) {
                if (el.classList.contains('neo-autocreate')) {
                  return '<div class="create">Create <strong>' + escape(data.input) + '</strong>&hellip;</div>';
                }
                return null;
              }
              new TomSelect(el, finalSettings);
            }
          });
        });
        observer.observe(el);
      });
    }
  };

})(Drupal, once, Popper);

export {};
