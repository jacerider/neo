(function (Drupal, once) {

  const baseSettings = {
    // dropdownParent: 'form',
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
          const parent = el.parentElement;
          if (parent) {
            parent.classList.add('neo-tom-wrapper');
            parent.classList.add('neo-tom-select-wrapper');
          }
          let settings = {
            allowEmptyOption: findEmptyOption(el) !== null,
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
          new TomSelect(el, {...settings, ...baseSettings});
        }
      });

      once('neo.tom', 'input.neo-entity-autocomplete').forEach(el => {
        const parent = el.parentElement;
        const multiple = el.classList.contains('neo-multi-select');
        if (parent) {
          parent.classList.add('neo-tom-wrapper');
        }
        let settings = {
          valueField: 'value',
          labelField: 'value',
          searchField: 'label',
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
        if (multiple) {
          settings = {...settings, ...{
            maxItems: null,
            onItemAdd: function(){
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
          console.log(settings);
        }
        new TomSelect(el, {...settings, ...baseSettings});
      });
    }
  };

})(Drupal, once);

export {};
