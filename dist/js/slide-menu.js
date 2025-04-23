var g = Object.defineProperty;
var w = (r, a, u) => a in r ? g(r, a, { enumerable: !0, configurable: !0, writable: !0, value: u }) : r[a] = u;
var h = (r, a, u) => w(r, typeof a != "symbol" ? a + "" : a, u);
function A(r, a) {
  if (r.parentElement === null)
    throw Error("`elem` has no parentElement");
  return r.parentElement.insertBefore(a, r), a.appendChild(r), r;
}
function S(r) {
  const a = r.parentElement;
  if (a === null)
    throw Error("`elem` has no parentElement");
  for (; r.firstChild; )
    a.insertBefore(r.firstChild, r);
  a.removeChild(r);
}
function k(r, a, u) {
  const d = [];
  for (; r && r.parentElement !== null && (u === void 0 || d.length < u); )
    r instanceof HTMLElement && r.matches(a) && d.push(r), r = r.parentElement;
  return d;
}
function L(r, a) {
  const u = k(r, a, 1);
  return u.length ? u[0] : null;
}
function $(r, a, u) {
  const d = [];
  let c = r.parentElement;
  for (; c && (c.matches(a) && d.push(c), c !== u); )
    c = c.parentElement;
  return d;
}
function b(r, a, u) {
  r.querySelectorAll("li").forEach((c) => {
    const l = document.createElement("div");
    if (a && l.classList.add(a), u) {
      const e = $(c, "ul", r);
      c.classList.add(`${u}-${e.length}`);
    }
    const y = Array.from(c.childNodes), o = [];
    y.forEach((e) => {
      e.nodeType === Node.ELEMENT_NODE && e.tagName.toLowerCase() === "ul" ? o.push(e) : l.appendChild(e.cloneNode(!0));
    }), c.innerHTML = "", l.hasChildNodes() && c.appendChild(l), o.forEach((e) => {
      c.appendChild(e), b(e);
    });
  });
}
(function(r) {
  let a;
  ((o) => {
    o[o.Backward = -1] = "Backward", o[o.Forward = 1] = "Forward";
  })(a || (a = {}));
  let u;
  ((o) => {
    o.Back = "back", o.Close = "close", o.Forward = "forward", o.Navigate = "navigate", o.Open = "open";
  })(u || (u = {}));
  const d = {
    backLinkAfter: "",
    backLinkBefore: "",
    position: "right",
    showBackLink: !1,
    submenuLinkAfter: "",
    submenuLinkBefore: ""
  }, c = "neo-slide-menu", l = {
    active: `${c}--active`,
    focus: `${c}--focus`,
    activeLi: "is-active",
    backlink: `${c}--backlink`,
    control: `${c}--control`,
    decorator: `${c}--decorator`,
    level: `${c}--level-`,
    wrapper: `${c}--slider`,
    wrapperLi: `${c}--item`
  };
  class y {
    constructor(e, t) {
      h(this, "level", 0);
      h(this, "isOpen", !1);
      h(this, "isAnimating", !1);
      h(this, "lastAction", null);
      h(this, "options");
      h(this, "menuElem");
      h(this, "wrapperElem");
      h(this, "focusElem", null);
      if (e === null)
        throw new Error("Argument `elem` must be a valid HTML node");
      this.options = Object.assign({}, d, t), this.menuElem = e, this.wrapperElem = document.createElement("div"), this.wrapperElem.classList.add(l.wrapper);
      const s = this.menuElem.querySelector("ul");
      s && (s.classList.add(l.active), A(s, this.wrapperElem)), this.initMenu(), this.setFocus(0), this.initSubmenus(), this.initEventHandlers(), this.menuElem._slideMenu = this;
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
      const { submenuLinkAfter: e, submenuLinkBefore: t, showBackLink: s } = this.options;
      (e || t) && Array.from(
        this.wrapperElem.querySelectorAll(`.${l.decorator}`)
      ).forEach((i) => {
        i.parentElement && i.parentElement.removeChild(i);
      }), s && Array.from(
        this.wrapperElem.querySelectorAll(`.${l.control}`)
      ).forEach((i) => {
        const m = L(i, "li");
        m && m.parentElement && m.parentElement.removeChild(m);
      }), S(this.wrapperElem), this.menuElem.style.cssText = "", this.menuElem.querySelectorAll("ul").forEach((n) => n.style.cssText = ""), delete this.menuElem._slideMenu;
    }
    /**
     * Navigate to a specific link on any level (useful to open the correct hierarchy directly)
     */
    navigateTo(e) {
      if (this.triggerEvent(
        "navigate"
        /* Navigate */
      ), typeof e == "string") {
        const i = document.querySelector(e);
        if (i instanceof HTMLElement)
          e = i;
        else
          throw new Error("Invalid parameter `target`. A valid query selector is required.");
      }
      Array.from(
        this.wrapperElem.querySelectorAll(`.${l.active}`)
      ).forEach((i) => {
        i.style.visibility = "hidden", i.classList.remove(l.active);
      });
      const s = k(e, "ul"), n = s.length - 1;
      s.forEach((i) => {
        i.style.visibility = "visible", i.classList.add(l.active);
      }), n >= 0 && n !== this.level && (this.level = n, this.moveSlider(this.wrapperElem, -this.level * 100));
    }
    /**
     * Set up all event handlers
     */
    initEventHandlers() {
      Array.from(this.menuElem.querySelectorAll("a")).forEach(
        (t) => t.addEventListener("click", (s) => {
          const n = s.target, i = n.matches("a") ? n : L(n, "a");
          i && this.navigate(1, i);
        })
      ), this.menuElem.addEventListener("transitionend", this.onTransitionEnd.bind(this)), this.wrapperElem.addEventListener("transitionend", this.onTransitionEnd.bind(this)), this.initSubmenuVisibility();
    }
    onTransitionEnd(e) {
      e.target !== this.menuElem && e.target !== this.wrapperElem || (this.isAnimating = !1, this.lastAction && (this.triggerEvent(this.lastAction, !0), this.lastAction = null));
    }
    setFocus(e) {
      const t = `.${l.active} `.repeat(e), s = this.menuElem.querySelector(
        `ul ${t}`
      );
      s && (this.menuElem.querySelectorAll(`ul.${l.focus}`).forEach((n) => {
        n.classList.remove(l.focus);
      }), s.classList.add(l.focus), this.focusElem = s, this.wrapperElem.style.height = `${s.clientHeight}px`);
    }
    initSubmenuVisibility() {
      this.menuElem.addEventListener("sm.back-after", () => {
        const e = `.${l.active} `.repeat(this.level + 1), t = this.menuElem.querySelector(
          `ul ${e}`
        );
        t && (t.style.visibility = "hidden", t.classList.remove(l.active));
      });
    }
    /**
     * Trigger a custom event to support callbacks
     */
    triggerEvent(e, t = !1) {
      this.lastAction = e;
      const s = `sm.${e}${t ? "-after" : ""}`, n = new CustomEvent(s);
      this.menuElem.dispatchEvent(n);
    }
    /**
     * Navigate the menu - that is slide it one step left or right
     */
    navigate(e = 1, t) {
      if (this.isAnimating || e === -1 && this.level === 0)
        return;
      const s = (this.level + e) * -100;
      if (t && t.parentElement !== null && e === 1) {
        const i = t.closest("li");
        if (!i)
          return;
        const m = i.querySelector("ul");
        if (!m)
          return;
        m.classList.add(l.active), m.style.visibility = "visible";
      }
      const n = e === 1 ? "forward" : "back";
      this.triggerEvent(n), this.level = this.level + e, this.moveSlider(this.wrapperElem, s);
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
      t.forEach((s) => s.style.transition = "none"), e(), this.menuElem.offsetHeight, t.forEach((s) => s.style.removeProperty("transition")), this.isAnimating = !1;
    }
    /**
     * Enhance the markup of menu items which contain a submenu
     */
    initSubmenus() {
      let e = null;
      b(this.menuElem, l.wrapperLi, l.level), this.menuElem.querySelectorAll("ul").forEach((t) => {
        t.querySelectorAll(`:scope > li > .${l.wrapperLi}`).forEach((n, i) => {
          n.style.animationDelay = `${300 + i * 30}ms`;
        });
      }), this.menuElem.querySelectorAll("a").forEach((t) => {
        if (t.parentElement === null)
          return;
        const s = t.href;
        s && new URL(s).pathname === window.location.pathname && t.parentElement && (e = t.parentElement);
        const n = t.closest("li");
        if (!n)
          return;
        const i = n.querySelector("ul");
        if (!i)
          return;
        t.addEventListener("click", (E) => {
          E.preventDefault();
        });
        const m = t.textContent;
        if (this.addLinkDecorators(t), this.options.showBackLink) {
          const { backLinkBefore: E, backLinkAfter: v } = this.options, f = document.createElement("a");
          f.innerHTML = E + m + v, f.classList.add(l.backlink, l.control), f.setAttribute(
            "data-action",
            "back"
            /* Back */
          );
          const p = document.createElement("li");
          p.appendChild(f), i.insertBefore(p, i.firstChild);
        }
      }), e instanceof HTMLElement && (e.classList.add(l.activeLi), this.runWithoutAnimation(() => {
        this.navigateTo(e);
      }));
    }
    // Add `before` and `after` text
    addLinkDecorators(e) {
      const { submenuLinkBefore: t, submenuLinkAfter: s } = this.options;
      if (t) {
        const n = document.createElement("span");
        n.classList.add(l.decorator), n.innerHTML = t, e.insertBefore(n, e.firstChild);
      }
      if (s) {
        const n = document.createElement("span");
        n.classList.add(l.decorator), n.innerHTML = s, e.appendChild(n);
      }
      return e;
    }
  }
  document.addEventListener("click", (o) => {
    if (!(o.target instanceof HTMLElement))
      return;
    const e = o.target.className.includes(l.control) ? o.target : L(o.target, `.${l.control}`);
    if (!e || !e.className.includes(l.control))
      return;
    const t = e.getAttribute("data-target"), s = !t || t === "this" ? L(e, `.${c}`) : document.getElementById(t);
    if (!s)
      throw new Error(`Unable to find menu ${t}`);
    const n = s._slideMenu, i = e.getAttribute("data-action"), m = e.getAttribute("data-arg");
    n && i && typeof n[i] == "function" && (m ? n[i](m) : n[i]());
  }), r.behaviors.neoSlideMenu = {
    attach: () => {
      once("neo-slide-menu", ".neo-slide-menu").forEach((o) => {
        new y(o);
      });
    },
    // When opening in a modal, you can pass this method as a titleCallback
    // and it will swap the title with the current menu item parent.
    modalTitle: (o, e) => {
      var s;
      const t = (s = o.getContent()) == null ? void 0 : s.querySelector(".neo-slide-menu");
      if (t) {
        const n = t._slideMenu;
        if (n && n.focusElem) {
          const i = document.createElement("div");
          i.style.transition = "opacity 300ms ease-in-out", i.classList.add("flex", "items-center", "gap-4", "hover:text-primary", "transition", "cursor-pointer"), i.addEventListener("click", (p) => {
            p.preventDefault(), n.back();
          });
          const m = document.createElement("div");
          m.innerHTML = '<i class="text-base-700 text-sm neo-icon neo-icon-font icon-regular-chevron-left" title="Back" aria-hidden="true"></i>', i.appendChild(m);
          const E = document.createElement("div");
          i.appendChild(E);
          const v = () => {
            i.style.opacity = "0";
          }, f = () => {
            let p = o.getOption("title");
            i.style.opacity = "1", i.style.pointerEvents = "none", m.style.display = "none", n && n.focusElem && n.focusElem.dataset.parentTitle && (i.style.pointerEvents = "", m.style.display = "block", p = n.focusElem.dataset.parentTitle), E.innerHTML = p;
          };
          t.addEventListener("sm.forward", v), t.addEventListener("sm.forward-after", f), t.addEventListener("sm.back", v), t.addEventListener("sm.back-after", f), e.appendChild(i), f();
        }
      }
      return "Menu";
    }
  };
})(Drupal);
//# sourceMappingURL=slide-menu.js.map
