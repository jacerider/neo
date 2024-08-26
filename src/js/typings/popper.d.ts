
import * as popper from '@popperjs/core';
declare global {
  namespace Popper {
    export function createPopper(reference: Element | popper.VirtualElement, popper: HTMLElement, options?: Partial<popper.OptionsGeneric<any>>): popper.Instance;
    type instance = popper.Instance;
  }
}
