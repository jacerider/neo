var w = Object.defineProperty;
var A = (r, o, c) => o in r ? w(r, o, { enumerable: !0, configurable: !0, writable: !0, value: c }) : r[o] = c;
var f = (r, o, c) => A(r, typeof o != "symbol" ? o + "" : o, c);
function S(r, o) {
  if (r.parentElement === null)
    throw Error("`elem` has no parentElement");
  return r.parentElement.insertBefore(o, r), o.appendChild(r), r;
}
function $(r) {
  const o = r.parentElement;
  if (o === null)
    throw Error("`elem` has no parentElement");
  for (; r.firstChild; )
    o.insertBefore(r.firstChild, r);
  o.removeChild(r);
}
function b(r, o, c) {
  const h = [];
  for (; r && r.parentElement !== null && (c === void 0 || h.length < c); )
    r instanceof HTMLElement && r.matches(o) && h.push(r), r = r.parentElement;
  return h;
}
function k(r, o) {
  const c = b(r, o, 1);
  return c.length ? c[0] : null;
}
function T(r, o, c) {
  const h = [];
  let m = r.parentElement;
  for (; m && (m.matches(o) && h.push(m), m !== c); )
    m = m.parentElement;
  return h;
}
function g(r, o, c) {
  r.querySelectorAll("li").forEach((m) => {
    const d = document.createElement("div");
    if (o && d.classList.add(o), c) {
      const l = T(m, "ul", r);
      m.classList.add(`${c}-${l.length}`);
    }
    const a = Array.from(m.childNodes), L = [];
    a.forEach((l) => {
      l.nodeType === Node.ELEMENT_NODE && l.tagName.toLowerCase() === "ul" ? L.push(l) : d.appendChild(l.cloneNode(!0));
    }), m.innerHTML = "", d.hasChildNodes() && m.appendChild(d), L.forEach((l) => {
      m.appendChild(l), g(l);
    });
  });
}
(function(r, o) {
  let c;
  ((l) => {
    l[l.Backward = -1] = "Backward", l[l.Forward = 1] = "Forward";
  })(c || (c = {}));
  let h;
  ((l) => {
    l.Back = "back", l.Close = "close", l.Forward = "forward", l.Navigate = "navigate", l.Open = "open";
  })(h || (h = {}));
  const m = {
    backLinkAfter: "",
    backLinkBefore: "",
    position: "right",
    showBackLink: !1,
    submenuLinkAfter: "",
    submenuLinkBefore: ""
  }, d = "neo-slide-menu", a = {
    active: `${d}--active`,
    focus: `${d}--focus`,
    activeLi: "is-active",
    backlink: `${d}--backlink`,
    control: `${d}--control`,
    decorator: `${d}--decorator`,
    level: `${d}--level-`,
    wrapper: `${d}--slider`,
    wrapperLi: `${d}--item`
  };
  class L {
    constructor(t, e) {
      f(this, "level", 0);
      f(this, "isOpen", !1);
      f(this, "isAnimating", !1);
      f(this, "lastAction", null);
      f(this, "options");
      f(this, "menuElem");
      f(this, "wrapperElem");
      f(this, "focusElem", null);
      if (t === null)
        throw new Error("Argument `elem` must be a valid HTML node");
      this.options = Object.assign({}, m, e), this.menuElem = t, this.wrapperElem = document.createElement("div"), this.wrapperElem.classList.add(a.wrapper);
      const s = this.menuElem.querySelector("ul");
      s && (s.classList.add(a.active), S(s, this.wrapperElem)), this.initMenu(), this.setFocus(0), this.initSubmenus(), this.initEventHandlers(), this.menuElem._slideMenu = this;
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
      const { submenuLinkAfter: t, submenuLinkBefore: e, showBackLink: s } = this.options;
      (t || e) && Array.from(
        this.wrapperElem.querySelectorAll(`.${a.decorator}`)
      ).forEach((i) => {
        i.parentElement && i.parentElement.removeChild(i);
      }), s && Array.from(
        this.wrapperElem.querySelectorAll(`.${a.control}`)
      ).forEach((i) => {
        const u = k(i, "li");
        u && u.parentElement && u.parentElement.removeChild(u);
      }), $(this.wrapperElem), this.menuElem.style.cssText = "", this.menuElem.querySelectorAll("ul").forEach((n) => n.style.cssText = ""), delete this.menuElem._slideMenu;
    }
    /**
     * Navigate to a specific link on any level (useful to open the correct hierarchy directly)
     */
    navigateTo(t) {
      if (this.triggerEvent(
        "navigate"
        /* Navigate */
      ), typeof t == "string") {
        const i = document.querySelector(t);
        if (i instanceof HTMLElement)
          t = i;
        else
          throw new Error("Invalid parameter `target`. A valid query selector is required.");
      }
      Array.from(
        this.wrapperElem.querySelectorAll(`.${a.active}`)
      ).forEach((i) => {
        i.style.visibility = "hidden", i.classList.remove(a.active);
      });
      const s = b(t, "ul"), n = s.length - 1;
      s.forEach((i) => {
        i.style.visibility = "visible", i.classList.add(a.active);
      }), n >= 0 && n !== this.level && (this.level = n, this.moveSlider(this.wrapperElem, -this.level * 100));
    }
    /**
     * Set up all event handlers
     */
    initEventHandlers() {
      Array.from(this.menuElem.querySelectorAll("a")).forEach(
        (e) => e.addEventListener("click", (s) => {
          const n = s.target, i = n.matches("a") ? n : k(n, "a");
          i && this.navigate(1, i);
        })
      ), this.menuElem.addEventListener("transitionend", this.onTransitionEnd.bind(this)), this.wrapperElem.addEventListener("transitionend", this.onTransitionEnd.bind(this)), this.initSubmenuVisibility();
    }
    onTransitionEnd(t) {
      t.target !== this.menuElem && t.target !== this.wrapperElem || (this.isAnimating = !1, this.lastAction && (this.triggerEvent(this.lastAction, !0), this.lastAction = null));
    }
    setFocus(t) {
      const e = `.${a.active} `.repeat(t), s = this.menuElem.querySelector(
        `ul ${e}`
      );
      s && (this.menuElem.querySelectorAll(`ul.${a.focus}`).forEach((n) => {
        n.classList.remove(a.focus);
      }), s.classList.add(a.focus), this.focusElem = s, this.wrapperElem.style.height = `${s.clientHeight}px`);
    }
    initSubmenuVisibility() {
      this.menuElem.addEventListener("sm.back-after", () => {
        const t = `.${a.active} `.repeat(this.level + 1), e = this.menuElem.querySelector(
          `ul ${t}`
        );
        e && (e.style.visibility = "hidden", e.classList.remove(a.active));
      });
    }
    /**
     * Trigger a custom event to support callbacks
     */
    triggerEvent(t, e = !1) {
      this.lastAction = t;
      const s = `sm.${t}${e ? "-after" : ""}`, n = new CustomEvent(s);
      this.menuElem.dispatchEvent(n);
    }
    /**
     * Navigate the menu - that is slide it one step left or right
     */
    navigate(t = 1, e) {
      if (this.isAnimating || t === -1 && this.level === 0)
        return;
      const s = (this.level + t) * -100;
      if (e && e.parentElement !== null && t === 1) {
        const i = e.closest("li");
        if (!i)
          return;
        const u = i.querySelector("ul");
        if (!u)
          return;
        u.classList.add(a.active), u.style.visibility = "visible";
      }
      const n = t === 1 ? "forward" : "back";
      this.triggerEvent(n), this.level = this.level + t, this.moveSlider(this.wrapperElem, s);
    }
    /**
     * Start the slide animation (the CSS transition)
     */
    moveSlider(t, e) {
      e.toString().includes("%") || (e += "%"), t.style.transform = `translateX(${e})`, this.isAnimating = !0, this.setFocus(this.level);
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
    runWithoutAnimation(t) {
      const e = [this.menuElem, this.wrapperElem];
      e.forEach((s) => s.style.transition = "none"), t(), this.menuElem.offsetHeight, e.forEach((s) => s.style.removeProperty("transition")), this.isAnimating = !1;
    }
    /**
     * Enhance the markup of menu items which contain a submenu
     */
    initSubmenus() {
      let t = null;
      g(this.menuElem, a.wrapperLi, a.level), this.menuElem.querySelectorAll("ul").forEach((e) => {
        e.querySelectorAll(`:scope > li > .${a.wrapperLi}`).forEach((n, i) => {
          n.style.animationDelay = `${300 + i * 30}ms`;
        });
      }), this.menuElem.querySelectorAll("a").forEach((e) => {
        if (e.parentElement === null)
          return;
        const s = e.href;
        s && new URL(s).pathname === window.location.pathname && e.parentElement && (t = e.parentElement);
        const n = e.closest("li");
        if (!n)
          return;
        const i = n.querySelector("ul");
        if (!i)
          return;
        e.addEventListener("click", (p) => {
          p.preventDefault();
        });
        const u = e.textContent;
        if (this.addLinkDecorators(e), this.options.showBackLink) {
          const { backLinkBefore: p, backLinkAfter: y } = this.options, E = document.createElement("a");
          E.innerHTML = p + u + y, E.classList.add(a.backlink, a.control), E.setAttribute(
            "data-action",
            "back"
            /* Back */
          );
          const v = document.createElement("li");
          v.appendChild(E), i.insertBefore(v, i.firstChild);
        }
      }), t instanceof HTMLElement && (t.classList.add(a.activeLi), this.runWithoutAnimation(() => {
        this.navigateTo(t);
      }));
    }
    // Add `before` and `after` text
    addLinkDecorators(t) {
      const { submenuLinkBefore: e, submenuLinkAfter: s } = this.options;
      if (e) {
        const n = document.createElement("span");
        n.classList.add(a.decorator), n.innerHTML = e, t.insertBefore(n, t.firstChild);
      }
      if (s) {
        const n = document.createElement("span");
        n.classList.add(a.decorator), n.innerHTML = s, t.appendChild(n);
      }
      return t;
    }
  }
  document.addEventListener("click", (l) => {
    if (!(l.target instanceof HTMLElement))
      return;
    const t = l.target.className.includes(a.control) ? l.target : k(l.target, `.${a.control}`);
    if (!t || !t.className.includes(a.control))
      return;
    const e = t.getAttribute("data-target"), s = !e || e === "this" ? k(t, `.${d}`) : document.getElementById(e);
    if (!s)
      throw new Error(`Unable to find menu ${e}`);
    const n = s._slideMenu, i = t.getAttribute("data-action"), u = t.getAttribute("data-arg");
    n && i && typeof n[i] == "function" && (u ? n[i](u) : n[i]());
  }), r.behaviors.neoSlideMenu = {
    attach: () => {
      o("neo-slide-menu", ".neo-slide-menu").forEach((l) => {
        new L(l);
      });
    },
    // When opening in a modal, you can pass this method as a titleCallback
    // and it will swap the title with the current menu item parent.
    modalTitle: (l, t) => {
      var s;
      const e = (s = l.getContent()) == null ? void 0 : s.querySelector(".neo-slide-menu");
      if (e) {
        const n = e._slideMenu;
        if (n && n.focusElem) {
          const i = document.createElement("div");
          i.style.transition = "opacity 300ms ease-in-out", i.classList.add("flex", "items-center", "gap-4", "hover:text-primary", "transition", "cursor-pointer"), i.addEventListener("click", (v) => {
            v.preventDefault(), n.back();
          });
          const u = document.createElement("div");
          u.innerHTML = '<i class="text-base-700 text-sm neo-icon neo-icon-font icon-regular-chevron-left" title="Back" aria-hidden="true"></i>', i.appendChild(u);
          const p = document.createElement("div");
          i.appendChild(p);
          const y = () => {
            i.style.opacity = "0";
          }, E = () => {
            let v = l.getOption("title");
            i.style.opacity = "1", i.style.pointerEvents = "none", u.style.display = "none", n && n.focusElem && n.focusElem.dataset.parentTitle && (i.style.pointerEvents = "", u.style.display = "block", v = n.focusElem.dataset.parentTitle), p.innerHTML = v;
          };
          e.addEventListener("sm.forward", y), e.addEventListener("sm.forward-after", E), e.addEventListener("sm.back", y), e.addEventListener("sm.back-after", E), t.appendChild(i), E();
        }
      }
      return "Menu";
    }
  };
})(Drupal, once);
//# sourceMappingURL=slide-menu.js.map
