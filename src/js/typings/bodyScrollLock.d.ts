interface BodyScrollLock {
  lock: (target?: HTMLElement|HTMLElement[], options?:any) => void;
  unlock: (target?: HTMLElement, options?:any) => void;
  clearBodyLocks: () => void;
}

declare var bodyScrollLock:BodyScrollLock;
