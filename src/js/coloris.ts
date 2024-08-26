(function (Drupal, once) {

  Drupal.behaviors.neoColoris = {
    attach: () => {
      window.setTimeout(function () {
        once('neo.coloris', '.coloris').forEach(el => {
          if (!el.classList.contains('coloris--processed')) {
            let id = el.getAttribute('id');
            let parentString = el.getAttribute("data-parent");
            let wrapString = el.getAttribute("data-wrap");
            let themeString = (el.getAttribute("data-theme") || 'default') as Coloris.Theme;
            let themeModeString = (el.getAttribute("data-theme-mode") || 'light') as Coloris.ThemeMode;
            let marginString = parseInt(el.getAttribute("data-margin") || '2');
            let formatString = (el.getAttribute("data-format") || 'hex') as Coloris.ColorFormat;
            let formatToggleString = el.getAttribute("data-format-toggle");
            let alphaString = el.getAttribute("data-alpha");
            let swatchesOnlyString = el.getAttribute("data-swatches-only");
            let focusInputString = el.getAttribute("data-focus-input");
            let clearButtonShowString = el.getAttribute("data-clear-button-show") === 'true';
            let clearButtonLabelString = el.getAttribute("data-clear-button-label") || '';
            let inlineString = el.getAttribute("data-inline");
            let onChange = el.getAttribute("data-on-change");
            let defaultColorString = el.getAttribute("data-default-color") || '';
            let swatchesString = el.getAttribute("data-swatches") as string;
            let swatchesObject = JSON.parse(decodeURIComponent(swatchesString));

            let settings = {
              el: el,
              wrap: wrapString === 'true',
              theme: themeString,
              themeMode: themeModeString,
              margin: marginString,
              format: formatString,
              formatToggle: formatToggleString === 'true',
              alpha: alphaString === 'true',
              swatchesOnly: swatchesOnlyString === 'true',
              focusInput: focusInputString === 'true',
              clearButton: {
                show: clearButtonShowString,
                label: clearButtonLabelString
              },
              swatches: swatchesObject,
              inline: inlineString === 'true',
              defaultColor: defaultColorString
            } as Coloris.ColorisOptions;

            if (parentString !== null) {
              settings.parent = parentString;
            }

            if (onChange !== null) {
              const resolve = (path:string, obj:any=self, separator='.') => {
                var properties = path.split(separator) as string[];
                return properties.reduce((prev, curr) => {
                  return prev?.[curr];
                }, obj);
              }
              const callback = resolve(onChange);
              if (callback) {
                settings.onChange = callback;
              }
            }

            Coloris.setInstance('#' + id, settings);
            Coloris(settings);

          }

        });
      });
    }
  };

})(Drupal, once);

export {};
