var L = Object.defineProperty;
var y = (r, e, t) => e in r ? L(r, e, { enumerable: !0, configurable: !0, writable: !0, value: t }) : r[e] = t;
var u = (r, e, t) => y(r, typeof e != "symbol" ? e + "" : e, t);
function b(r, e) {
  if (r.parentElement === null)
    throw Error("`elem` has no parentElement");
  return r.parentElement.insertBefore(e, r), e.appendChild(r), r;
}
function g(r) {
  const e = r.parentElement;
  if (e === null)
    throw Error("`elem` has no parentElement");
  for (; r.firstChild; )
    e.insertBefore(r.firstChild, r);
  e.removeChild(r);
}
function p(r, e, t) {
  const i = [];
  for (; r && r.parentElement !== null && (t === void 0 || i.length < t); )
    r instanceof HTMLElement && r.matches(e) && i.push(r), r = r.parentElement;
  return i;
}
function E(r, e) {
  const t = p(r, e, 1);
  return t.length ? t[0] : null;
}
function k(r, e, t) {
  const i = [];
  let n = r.parentElement;
  for (; n && (n.matches(e) && i.push(n), n !== t); )
    n = n.parentElement;
  return i;
}
function v(r, e, t) {
  r.querySelectorAll("li").forEach((n) => {
    const s = document.createElement("div");
    if (e && s.classList.add(e), t) {
      const o = k(n, "ul", r);
      n.classList.add(`${t}-${o.length}`);
    }
    const l = Array.from(n.childNodes), c = [];
    l.forEach((o) => {
      o.nodeType === Node.ELEMENT_NODE && o.tagName.toLowerCase() === "ul" ? c.push(o) : s.appendChild(o.cloneNode(!0));
    }), n.innerHTML = "", s.hasChildNodes() && n.appendChild(s), c.forEach((o) => {
      n.appendChild(o), v(o);
    });
  });
}
const A = {
  backLinkAfter: "",
  backLinkBefore: "",
  position: "right",
  showBackLink: !1,
  submenuLinkAfter: "",
  submenuLinkBefore: ""
}, m = "neo-slide-menu", a = {
  active: `${m}--active`,
  focus: `${m}--focus`,
  activeLi: "is-active",
  backlink: `${m}--backlink`,
  control: `${m}--control`,
  decorator: `${m}--decorator`,
  level: `${m}--level-`,
  wrapper: `${m}--slider`,
  wrapperLi: `${m}--item`
};
class w {
  constructor(e, t) {
    u(this, "level", 0);
    u(this, "isOpen", !1);
    u(this, "isAnimating", !1);
    u(this, "lastAction", null);
    u(this, "options");
    u(this, "menuElem");
    u(this, "wrapperElem");
    u(this, "focusElem", null);
    if (e === null)
      throw new Error("Argument `elem` must be a valid HTML node");
    this.options = Object.assign({}, A, t), this.menuElem = e, this.wrapperElem = document.createElement("div"), this.wrapperElem.classList.add(a.wrapper);
    const i = this.menuElem.querySelector("ul");
    i && (i.classList.add(a.active), b(i, this.wrapperElem)), this.initMenu(), this.setFocus(0), this.initSubmenus(), this.initEventHandlers(), this.menuElem._slideMenu = this;
  }
  /**
   * Navigate one menu hierarchy back if possible
   */
  back() {
    this.navigate(
      -1
      /* Backward */
    );
  }
  /**
   * Destroy the SlideMenu
   */
  destroy() {
    const { submenuLinkAfter: e, submenuLinkBefore: t, showBackLink: i } = this.options;
    (e || t) && Array.from(
      this.wrapperElem.querySelectorAll(`.${a.decorator}`)
    ).forEach((s) => {
      s.parentElement && s.parentElement.removeChild(s);
    }), i && Array.from(
      this.wrapperElem.querySelectorAll(`.${a.control}`)
    ).forEach((s) => {
      const l = E(s, "li");
      l && l.parentElement && l.parentElement.removeChild(l);
    }), g(this.wrapperElem), this.menuElem.style.cssText = "", this.menuElem.querySelectorAll("ul").forEach((n) => n.style.cssText = ""), delete this.menuElem._slideMenu;
  }
  /**
   * Navigate to a specific link on any level (useful to open the correct hierarchy directly)
   */
  navigateTo(e) {
    if (this.triggerEvent(
      "navigate"
      /* Navigate */
    ), typeof e == "string") {
      const s = document.querySelector(e);
      if (s instanceof HTMLElement)
        e = s;
      else
        throw new Error("Invalid parameter `target`. A valid query selector is required.");
    }
    Array.from(
      this.wrapperElem.querySelectorAll(`.${a.active}`)
    ).forEach((s) => {
      s.style.visibility = "hidden", s.classList.remove(a.active);
    });
    const i = p(e, "ul"), n = i.length - 1;
    i.forEach((s) => {
      s.style.visibility = "visible", s.classList.add(a.active);
    }), n >= 0 && n !== this.level && (this.level = n, this.moveSlider(this.wrapperElem, -this.level * 100));
  }
  /**
   * Set up all event handlers
   */
  initEventHandlers() {
    Array.from(this.menuElem.querySelectorAll("a")).forEach(
      (t) => t.addEventListener("click", (i) => {
        const n = i.target, s = n.matches("a") ? n : E(n, "a");
        s && this.navigate(1, s);
      })
    ), this.menuElem.addEventListener("transitionend", this.onTransitionEnd.bind(this)), this.wrapperElem.addEventListener("transitionend", this.onTransitionEnd.bind(this)), this.initSubmenuVisibility();
  }
  onTransitionEnd(e) {
    e.target !== this.menuElem && e.target !== this.wrapperElem || (this.isAnimating = !1, this.lastAction && (this.triggerEvent(this.lastAction, !0), this.lastAction = null));
  }
  setFocus(e) {
    const t = `.${a.active} `.repeat(e), i = this.menuElem.querySelector(
      `ul ${t}`
    );
    i && (this.menuElem.querySelectorAll(`ul.${a.focus}`).forEach((n) => {
      n.classList.remove(a.focus);
    }), i.classList.add(a.focus), this.focusElem = i, this.wrapperElem.style.height = `${i.clientHeight}px`);
  }
  initSubmenuVisibility() {
    this.menuElem.addEventListener("sm.back-after", () => {
      const e = `.${a.active} `.repeat(this.level + 1), t = this.menuElem.querySelector(
        `ul ${e}`
      );
      t && (t.style.visibility = "hidden", t.classList.remove(a.active));
    });
  }
  /**
   * Trigger a custom event to support callbacks
   */
  triggerEvent(e, t = !1) {
    this.lastAction = e;
    const i = `sm.${e}${t ? "-after" : ""}`, n = new CustomEvent(i);
    this.menuElem.dispatchEvent(n);
  }
  /**
   * Navigate the menu - that is slide it one step left or right
   */
  navigate(e = 1, t) {
    if (this.isAnimating || e === -1 && this.level === 0)
      return;
    const i = (this.level + e) * -100;
    if (t && t.parentElement !== null && e === 1) {
      const s = t.closest("li");
      if (!s)
        return;
      const l = s.querySelector("ul");
      if (!l)
        return;
      l.classList.add(a.active), l.style.visibility = "visible";
    }
    const n = e === 1 ? "forward" : "back";
    this.triggerEvent(n), this.level = this.level + e, this.moveSlider(this.wrapperElem, i);
  }
  /**
   * Start the slide animation (the CSS transition)
   */
  moveSlider(e, t) {
    t.toString().includes("%") || (t += "%"), e.style.transform = `translateX(${t})`, this.isAnimating = !0, this.setFocus(this.level);
  }
  /**
   * Initialize the menu
   */
  initMenu() {
    this.runWithoutAnimation(() => {
      switch (this.options.position) {
        case "left":
          Object.assign(this.menuElem.style, {
            left: 0,
            right: "auto",
            transform: "translateX(-100%)"
          });
          break;
        case "right":
          Object.assign(this.menuElem.style, {
            left: "auto",
            right: 0
          });
          break;
      }
      this.menuElem.style.visibility = "visible";
    });
  }
  /**
   * Pause the CSS transitions, to apply CSS changes directly without an animation
   */
  runWithoutAnimation(e) {
    const t = [this.menuElem, this.wrapperElem];
    t.forEach((i) => i.style.transition = "none"), e(), this.menuElem.offsetHeight, t.forEach((i) => i.style.removeProperty("transition")), this.isAnimating = !1;
  }
  /**
   * Enhance the markup of menu items which contain a submenu
   */
  initSubmenus() {
    let e = null;
    v(this.menuElem, a.wrapperLi, a.level), this.menuElem.querySelectorAll("ul").forEach((t) => {
      t.querySelectorAll(`:scope > li > .${a.wrapperLi}`).forEach((n, s) => {
        n.style.animationDelay = `${300 + s * 30}ms`;
      });
    }), this.menuElem.querySelectorAll("a").forEach((t) => {
      if (t.parentElement === null)
        return;
      const i = t.href;
      i && new URL(i).pathname === window.location.pathname && t.parentElement && (e = t.parentElement);
      const n = t.closest("li");
      if (!n)
        return;
      const s = n.querySelector("ul");
      if (!s)
        return;
      t.addEventListener("click", (c) => {
        c.preventDefault();
      });
      const l = t.textContent;
      if (this.addLinkDecorators(t), this.options.showBackLink) {
        const { backLinkBefore: c, backLinkAfter: o } = this.options, h = document.createElement("a");
        h.innerHTML = c + l + o, h.classList.add(a.backlink, a.control), h.setAttribute(
          "data-action",
          "back"
          /* Back */
        );
        const d = document.createElement("li");
        d.appendChild(h), s.insertBefore(d, s.firstChild);
      }
    }), e instanceof HTMLElement && (e.classList.add(a.activeLi), this.runWithoutAnimation(() => {
      this.navigateTo(e);
    }));
  }
  // Add `before` and `after` text
  addLinkDecorators(e) {
    const { submenuLinkBefore: t, submenuLinkAfter: i } = this.options;
    if (t) {
      const n = document.createElement("span");
      n.classList.add(a.decorator), n.innerHTML = t, e.insertBefore(n, e.firstChild);
    }
    if (i) {
      const n = document.createElement("span");
      n.classList.add(a.decorator), n.innerHTML = i, e.appendChild(n);
    }
    return e;
  }
}
document.addEventListener("click", (r) => {
  if (!(r.target instanceof HTMLElement))
    return;
  const e = r.target.className.includes(a.control) ? r.target : E(r.target, `.${a.control}`);
  if (!e || !e.className.includes(a.control))
    return;
  const t = e.getAttribute("data-target"), i = !t || t === "this" ? E(e, `.${m}`) : document.getElementById(t);
  if (!i)
    throw new Error(`Unable to find menu ${t}`);
  const n = i._slideMenu, s = e.getAttribute("data-action"), l = e.getAttribute("data-arg");
  n && s && typeof n[s] == "function" && (l ? n[s](l) : n[s]());
});
(function(r) {
  r.behaviors.neoSlideMenu = {
    attach: () => {
      once("neo-slide-menu", ".neo-slide-menu").forEach((e) => {
        new w(e);
      });
    },
    // When opening in a modal, you can pass this method as a titleCallback
    // and it will swap the title with the current menu item parent.
    modalTitle: (e, t) => {
      var n;
      const i = (n = e.getContent()) == null ? void 0 : n.querySelector(".neo-slide-menu");
      if (i) {
        const s = i._slideMenu;
        if (s && s.focusElem) {
          const l = document.createElement("div");
          l.style.transition = "opacity 300ms ease-in-out", l.classList.add("flex", "items-center", "gap-4", "hover:text-primary", "transition", "cursor-pointer"), l.addEventListener("click", (f) => {
            f.preventDefault(), s.back();
          });
          const c = document.createElement("div");
          c.innerHTML = '<i class="text-base-700 text-sm neo-icon neo-icon-font icon-regular-chevron-left" title="Back" aria-hidden="true"></i>', l.appendChild(c);
          const o = document.createElement("div");
          l.appendChild(o);
          const h = () => {
            l.style.opacity = "0";
          }, d = () => {
            let f = e.getOption("title");
            l.style.opacity = "1", l.style.pointerEvents = "none", c.style.display = "none", s && s.focusElem && s.focusElem.dataset.parentTitle && (l.style.pointerEvents = "", c.style.display = "block", f = s.focusElem.dataset.parentTitle), o.innerHTML = f;
          };
          i.addEventListener("sm.forward", h), i.addEventListener("sm.forward-after", d), i.addEventListener("sm.back", h), i.addEventListener("sm.back-after", d), t.appendChild(l), d();
        }
      }
      return "Menu";
    }
  };
})(Drupal);
//# sourceMappingURL=slide-menu.js.map
