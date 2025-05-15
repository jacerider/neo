function g(l, c) {
  if (l.parentElement === null)
    throw Error("`elem` has no parentElement");
  return l.parentElement.insertBefore(c, l), c.appendChild(l), l;
}
function w(l) {
  const c = l.parentElement;
  if (c === null)
    throw Error("`elem` has no parentElement");
  for (; l.firstChild; )
    c.insertBefore(l.firstChild, l);
  c.removeChild(l);
}
function k(l, c, h) {
  const d = [];
  for (; l && l.parentElement !== null && (h === void 0 || d.length < h); )
    l instanceof HTMLElement && l.matches(c) && d.push(l), l = l.parentElement;
  return d;
}
function y(l, c) {
  const h = k(l, c, 1);
  return h.length ? h[0] : null;
}
function A(l, c, h) {
  const d = [];
  let u = l.parentElement;
  for (; u && (u.matches(c) && d.push(u), u !== h); )
    u = u.parentElement;
  return d;
}
function b(l, c, h) {
  l.querySelectorAll("li").forEach((u) => {
    const m = document.createElement("div");
    if (c && m.classList.add(c), h) {
      const r = A(u, "ul", l);
      u.classList.add(`${h}-${r.length}`);
    }
    const a = Array.from(u.childNodes), v = [];
    a.forEach((r) => {
      r.nodeType === Node.ELEMENT_NODE && r.tagName.toLowerCase() === "ul" ? v.push(r) : m.appendChild(r.cloneNode(!0));
    }), u.innerHTML = "", m.hasChildNodes() && u.appendChild(m), v.forEach((r) => {
      u.appendChild(r), b(r);
    });
  });
}
(function(l, c) {
  let h;
  ((r) => {
    r[r.Backward = -1] = "Backward", r[r.Forward = 1] = "Forward";
  })(h || (h = {}));
  let d;
  ((r) => {
    r.Back = "back", r.Close = "close", r.Forward = "forward", r.Navigate = "navigate", r.Open = "open";
  })(d || (d = {}));
  const u = {
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
  class v {
    constructor(t, e) {
      if (this.level = 0, this.isOpen = !1, this.isAnimating = !1, this.lastAction = null, this.focusElem = null, t === null)
        throw new Error("Argument `elem` must be a valid HTML node");
      this.options = Object.assign({}, u, e), this.menuElem = t, this.wrapperElem = document.createElement("div"), this.wrapperElem.classList.add(a.wrapper);
      const s = this.menuElem.querySelector("ul");
      s && (s.classList.add(a.active), g(s, this.wrapperElem)), this.initMenu(), this.setFocus(0), this.initSubmenus(), this.initEventHandlers(), this.menuElem._slideMenu = this;
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
        const o = y(i, "li");
        o && o.parentElement && o.parentElement.removeChild(o);
      }), w(this.wrapperElem), this.menuElem.style.cssText = "", this.menuElem.querySelectorAll("ul").forEach((n) => n.style.cssText = ""), delete this.menuElem._slideMenu;
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
      const s = k(t, "ul"), n = s.length - 1;
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
          const n = s.target, i = n.matches("a") ? n : y(n, "a");
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
        const o = i.querySelector("ul");
        if (!o)
          return;
        o.classList.add(a.active), o.style.visibility = "visible";
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
      b(this.menuElem, a.wrapperLi, a.level), this.menuElem.querySelectorAll("ul").forEach((e) => {
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
        e.addEventListener("click", (E) => {
          E.preventDefault();
        });
        const o = e.textContent;
        if (this.addLinkDecorators(e), this.options.showBackLink) {
          const { backLinkBefore: E, backLinkAfter: L } = this.options, f = document.createElement("a");
          f.innerHTML = E + o + L, f.classList.add(a.backlink, a.control), f.setAttribute(
            "data-action",
            "back"
            /* Back */
          );
          const p = document.createElement("li");
          p.appendChild(f), i.insertBefore(p, i.firstChild);
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
  document.addEventListener("click", (r) => {
    if (!(r.target instanceof HTMLElement))
      return;
    const t = r.target.className.includes(a.control) ? r.target : y(r.target, `.${a.control}`);
    if (!t || !t.className.includes(a.control))
      return;
    const e = t.getAttribute("data-target"), s = !e || e === "this" ? y(t, `.${m}`) : document.getElementById(e);
    if (!s)
      throw new Error(`Unable to find menu ${e}`);
    const n = s._slideMenu, i = t.getAttribute("data-action"), o = t.getAttribute("data-arg");
    n && i && typeof n[i] == "function" && (o ? n[i](o) : n[i]());
  }), l.behaviors.neoSlideMenu = {
    attach: () => {
      c("neo-slide-menu", ".neo-slide-menu").forEach((r) => {
        new v(r);
      });
    },
    // When opening in a modal, you can pass this method as a titleCallback
    // and it will swap the title with the current menu item parent.
    modalTitle: (r, t) => {
      var s;
      const e = (s = r.getContent()) == null ? void 0 : s.querySelector(".neo-slide-menu");
      if (e) {
        const n = e._slideMenu;
        if (n && n.focusElem) {
          const i = document.createElement("div");
          i.style.transition = "opacity 300ms ease-in-out", i.classList.add("flex", "items-center", "gap-4", "hover:text-primary", "transition", "cursor-pointer"), i.addEventListener("click", (p) => {
            p.preventDefault(), n.back();
          });
          const o = document.createElement("div");
          o.innerHTML = '<i class="text-base-700 text-sm neo-icon neo-icon-font icon-regular-chevron-left" title="Back" aria-hidden="true"></i>', i.appendChild(o);
          const E = document.createElement("div");
          i.appendChild(E);
          const L = () => {
            i.style.opacity = "0";
          }, f = () => {
            let p = r.getOption("title");
            i.style.opacity = "1", i.style.pointerEvents = "none", o.style.display = "none", n && n.focusElem && n.focusElem.dataset.parentTitle && (i.style.pointerEvents = "", o.style.display = "block", p = n.focusElem.dataset.parentTitle), E.innerHTML = p;
          };
          e.addEventListener("sm.forward", L), e.addEventListener("sm.forward-after", f), e.addEventListener("sm.back", L), e.addEventListener("sm.back-after", f), t.appendChild(i), f();
        }
      }
      return "Menu";
    }
  };
})(Drupal, once);
//# sourceMappingURL=slide-menu.js.map
