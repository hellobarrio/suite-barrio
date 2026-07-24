/* Suite Barrio Consent Engine v1 — standalone, no dependencies */
(function (w, d) {
  "use strict";

  var COOKIE_NAME = "wpb_consent";
  var SCHEMA_V = 1;
  var MONTHS = 6;

  /* ── Cookie utils ── */
  function readCookie(name) {
    var m = d.cookie.match(
      new RegExp(
        "(?:^|;)\\s*" +
          name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") +
          "=([^;]*)",
      ),
    );
    return m ? decodeURIComponent(m[1]) : null;
  }

  function writeCookie(name, value, months) {
    var exp = new Date();
    exp.setMonth(exp.getMonth() + months);
    var secure = location.protocol === "https:" ? "; Secure" : "";
    d.cookie =
      name +
      "=" +
      encodeURIComponent(value) +
      "; expires=" +
      exp.toUTCString() +
      "; path=/; SameSite=Lax" +
      secure;
  }

  function parseConsent() {
    try {
      var raw = readCookie(COOKIE_NAME);
      if (!raw) return null;
      var p = JSON.parse(raw);
      if (p.v !== SCHEMA_V) return null;
      var maxAge = MONTHS * 30 * 24 * 3600;
      if (Math.floor(Date.now() / 1000) - (p.ts || 0) > maxAge) return null;
      return p;
    } catch (e) {
      return null;
    }
  }

  function defaults() {
    return {
      necessary: true,
      functional: false,
      analytics: false,
      marketing: false,
    };
  }

  /* ── State ── */
  var consent = parseConsent();
  var listeners = { change: [], ready: [] };
  var cfg = null;
  var prefHtml = null;

  /* ── Events ── */
  function emit(ev, data) {
    (listeners[ev] || []).forEach(function (fn) {
      fn(data);
    });
  }

  function on(ev, fn) {
    if (!listeners[ev]) listeners[ev] = [];
    listeners[ev].push(fn);
    return function () {
      listeners[ev] = listeners[ev].filter(function (f) {
        return f !== fn;
      });
    };
  }

  /* ── Save + activate ── */
  function save(prefs) {
    prefs.necessary = true;
    var data = Object.assign(
      { v: SCHEMA_V, ts: Math.floor(Date.now() / 1000) },
      prefs,
    );
    writeCookie(COOKIE_NAME, JSON.stringify(data), MONTHS);
    consent = data;
    gtmUpdate(prefs);
    activate(prefs);
    logConsent(prefs);
    emit("change", Object.assign({}, prefs));
    d.dispatchEvent(
      new CustomEvent("wpb:consent-change", {
        detail: { consent: Object.assign({}, data) },
      }),
    );
  }

  /* ── UUID v4 ── */
  function uuidv4() {
    if (typeof w.crypto !== "undefined" && w.crypto.randomUUID) {
      return w.crypto.randomUUID();
    }
    return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(
      /[xy]/g,
      function (c) {
        var r = (Math.random() * 16) | 0;
        var v = c === "x" ? r : (r & 0x3) | 0x8;
        return v.toString(16);
      },
    );
  }

  /* ── Rest base (stessa logica di loadConfig) ── */
  function restBase() {
    return (
      w.SuiteBarrioRestBase ||
      (d.currentScript && d.currentScript.src
        ? new URL(d.currentScript.src).origin + "/wp-json"
        : location.origin + "/wp-json")
    );
  }

  /* ── Log consenso lato server (fire-and-forget) ── */
  function logConsent(prefs) {
    var categories = ["functional", "analytics", "marketing"].filter(
      function (cat) {
        return !!prefs[cat];
      },
    );
    var status = categories.length > 0 ? "accept" : "reject";
    var headers = { "Content-Type": "application/json" };
    if (cfg && cfg.consentNonce) {
      headers["X-WP-Nonce"] = cfg.consentNonce;
    }
    try {
      fetch(restBase() + "/suite-barrio/v1/cookie-banner/consent-log", {
        method: "POST",
        keepalive: true,
        headers: headers,
        body: JSON.stringify({
          id: uuidv4(),
          status: status,
          categories: categories,
          url: location.href,
        }),
      }).catch(function () {});
    } catch (e) {}
  }

  function activate(prefs) {
    ["functional", "analytics", "marketing"].forEach(function (cat) {
      if (!prefs[cat]) return;
      d.querySelectorAll(
        'script[type="text/plain"][data-suite-barrio-category="' + cat + '"]',
      ).forEach(function (el) {
        var clone = d.createElement("script");
        Array.from(el.attributes).forEach(function (a) {
          if (a.name !== "type") clone.setAttribute(a.name, a.value);
        });
        clone.type = "text/javascript";
        if (el.innerHTML) clone.innerHTML = el.innerHTML;
        el.parentNode.replaceChild(clone, el);
      });
    });
  }

  /* ── GTM Consent Mode v2 ── */
  function toGtmMap(prefs) {
    var p = prefs || {};
    return {
      security_storage: "granted",
      functionality_storage: p.functional ? "granted" : "denied",
      personalization_storage: p.functional ? "granted" : "denied",
      analytics_storage: p.analytics ? "granted" : "denied",
      ad_storage: p.marketing ? "granted" : "denied",
      ad_user_data: p.marketing ? "granted" : "denied",
      ad_personalization: p.marketing ? "granted" : "denied",
    };
  }

  function gtmUpdate(prefs) {
    if (typeof w.gtag === "function")
      w.gtag("consent", "update", toGtmMap(prefs));
    (w.dataLayer = w.dataLayer || []).push({
      event: "wpb_consent_update",
      wpb_consent: Object.assign({}, prefs),
    });
  }

  /* ── Config ── */
  function loadConfig() {
    if (cfg) return Promise.resolve(cfg);
    if (w.SuiteBarrioConfig) {
      cfg = w.SuiteBarrioConfig;
      return Promise.resolve(cfg);
    }
    var base =
      w.SuiteBarrioRestBase ||
      (d.currentScript && d.currentScript.src
        ? new URL(d.currentScript.src).origin + "/wp-json"
        : location.origin + "/wp-json");
    return fetch(base + "/suite-barrio/v1/cookie-banner/config", { cache: "no-store" })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        cfg = data;
        return data;
      });
  }

  /* ── Preferences HTML ── */
  function loadPrefHtml(config) {
    if (prefHtml) return Promise.resolve(prefHtml);
    var url =
      (config && config.preferencesUrl) ||
      "/wp-json/suite-barrio/v1/cookie-banner/preferences-html";
    return fetch(url)
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        prefHtml = data.html || "";
        return prefHtml;
      });
  }

  /* ── Wire preferences widget ── */
  function wirePref(container, config) {
    var cur = consent ? Object.assign({}, consent) : defaults();
    container.querySelectorAll("[data-suite-barrio-toggle]").forEach(function (cb) {
      var cat = cb.getAttribute("data-suite-barrio-toggle");
      cb.checked = !!cur[cat];
      cb.disabled = cat === "necessary";
    });
    container.querySelectorAll("[data-suite-barrio-action]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var action = btn.getAttribute("data-suite-barrio-action");
        var prefs;
        if (action === "accept-all") {
          prefs = {
            necessary: true,
            functional: true,
            analytics: true,
            marketing: true,
          };
        } else if (action === "reject-all") {
          prefs = defaults();
        } else {
          prefs = { necessary: true };
          container
            .querySelectorAll("[data-suite-barrio-toggle]")
            .forEach(function (cb) {
              var cat = cb.getAttribute("data-suite-barrio-toggle");
              if (cat !== "necessary") prefs[cat] = cb.checked;
            });
        }
        save(prefs);
        var ov = d.getElementById("suite-barrio-pref-overlay");
        if (ov) ov.remove();
        var b = d.getElementById("suite-barrio-banner");
        if (b) b.remove();
        renderWidget(config);
      });
    });
  }

  /* ── Preferences drawer ── */
  function openPref(config) {
    if (d.getElementById("suite-barrio-pref-overlay")) return;
    var b  = (config && config.banner) || {};
    var bg = b.color_bg   || "#0D1B2E";
    var fg = b.color_text || "#FFFFFF";

    var ov = d.createElement("div");
    ov.id = "suite-barrio-pref-overlay";
    ov.style.cssText =
      "position:fixed;inset:0;z-index:2147483646;background:rgba(0,0,0,.5)";

    var drawer = d.createElement("div");
    drawer.setAttribute("role", "dialog");
    drawer.setAttribute("aria-modal", "true");
    drawer.setAttribute("aria-label", "Preferenze cookie");
    drawer.style.cssText =
      "position:absolute;top:0;right:0;bottom:0;width:min(480px,100vw);" +
      "background:#fff;overflow-y:auto;box-shadow:-4px 0 32px rgba(0,0,0,.2);" +
      "font-family:system-ui,sans-serif;display:flex;flex-direction:column;";

    /* Header strip — segue i colori del banner */
    var header = d.createElement("div");
    header.style.cssText =
      "position:sticky;top:0;z-index:1;flex-shrink:0;" +
      "background:" + bg + ";color:" + fg + ";" +
      "padding:18px 48px 18px 20px;border-bottom:1px solid rgba(255,255,255,.1);";
    var title = d.createElement("strong");
    title.style.cssText = "font-size:15px;display:block;";
    title.textContent = b.text_title || "Gestione cookie";
    header.appendChild(title);

    var closeBtn = d.createElement("button");
    closeBtn.type = "button";
    closeBtn.setAttribute("aria-label", "Chiudi");
    closeBtn.style.cssText =
      "position:absolute;top:14px;right:14px;border:none;background:none;" +
      "font-size:22px;line-height:1;cursor:pointer;color:" + fg + ";opacity:.8;padding:2px 6px;";
    closeBtn.textContent = "×";
    closeBtn.addEventListener("click", function () { ov.remove(); });
    header.appendChild(closeBtn);
    drawer.appendChild(header);

    var inner = d.createElement("div");
    inner.style.cssText = "padding:20px;flex:1;";
    inner.innerHTML = '<p style="color:#9ca3af;font-size:14px;">Caricamento…</p>';
    drawer.appendChild(inner);

    ov.appendChild(drawer);
    ov.addEventListener("click", function (e) { if (e.target === ov) ov.remove(); });
    d.body.appendChild(ov);
    ov.addEventListener("keydown", function (e) { if (e.key === "Escape") ov.remove(); });

    loadPrefHtml(config).then(function (html) {
      inner.innerHTML = html;
      /* Pulsante primario sempre scuro: usa il colore di sfondo del banner */
      inner.querySelectorAll(".suite-barrio-pref-btn-primary").forEach(function (btn) {
        btn.style.background = bg;
        btn.style.color = fg;
      });
      wirePref(inner, config);
      var first = drawer.querySelector(
        "button:not([disabled]),input:not([disabled]),[tabindex]"
      );
      if (first) first.focus();
    });
  }

  /* ── Banner ── */
  function renderBanner(config) {
    if (d.getElementById("suite-barrio-banner")) return;
    var b = (config && config.banner) || {};
    var bg = b.color_bg || "#0D1B2E";
    var fg = b.color_text || "#FFFFFF";
    var acc = b.color_accent || "#1A56DB";
    var pos = b.position || "bottom";

    var el = d.createElement("div");
    el.id = "suite-barrio-banner";
    el.setAttribute("role", "dialog");
    el.setAttribute("aria-live", "polite");
    el.setAttribute("aria-label", b.text_title || "Gestione cookie");

    var posStyle = "";
    if (pos === "bottom-left") {
      posStyle = "bottom:30px;left:30px;top:auto;right:auto;width:min(400px,96vw);border-radius:12px;";
    } else if (pos === "bottom-right") {
      posStyle = "bottom:30px;right:30px;top:auto;left:auto;width:min(400px,96vw);border-radius:12px;";
    } else {
      posStyle = "bottom:30px;left:50%;transform:translateX(-50%);top:auto;right:auto;width:min(560px,96vw);border-radius:12px;";
    }

    el.style.cssText =
      "position:fixed;" +
      posStyle +
      "z-index:2147483645;" +
      "background:" +
      bg +
      ";color:" +
      fg +
      ";padding:20px 24px;" +
      "display:flex;flex-wrap:wrap;align-items:center;gap:12px;" +
      "font-family:system-ui,sans-serif;font-size:14px;line-height:1.5;" +
      "box-shadow:0 4px 24px rgba(0,0,0,.28);";

    var txt = d.createElement("div");
    txt.style.cssText = "min-width:180px;";
    if (b.text_title) {
      var strong = d.createElement("strong");
      strong.style.display = "block";
      strong.textContent = b.text_title;
      txt.appendChild(strong);
    }
    if (b.text_body) {
      var span = d.createElement("span");
      span.style.fontSize = "13px";
      span.style.opacity = "0.85";
      span.textContent = b.text_body;
      txt.appendChild(span);
    }

    var btns = d.createElement("div");
    btns.style.cssText = "display:flex;flex-wrap:wrap;gap:8px;";

    function mkBtn(label, primary, handler) {
      var btn = d.createElement("button");
      btn.type = "button";
      btn.textContent = label;
      btn.style.cssText =
        "border:none;cursor:pointer;padding:8px 16px;border-radius:6px;" +
        "font-size:13px;font-weight:600;transition:opacity .15s;" +
        (primary
          ? "background:" + acc + ";color:#fff;"
          : "background:transparent;color:" +
            fg +
            ";outline:1px solid currentColor;");
      btn.addEventListener("click", handler);
      btn.addEventListener("mouseenter", function () {
        btn.style.opacity = "0.8";
      });
      btn.addEventListener("mouseleave", function () {
        btn.style.opacity = "1";
      });
      return btn;
    }

    btns.appendChild(
      mkBtn(b.text_accept || "Accetta tutti", true, function () {
        save({
          necessary: true,
          functional: true,
          analytics: true,
          marketing: true,
        });
        el.remove();
        renderWidget(config);
      }),
    );
    btns.appendChild(
      mkBtn(b.text_reject || "Rifiuta tutti", false, function () {
        save(defaults());
        el.remove();
        renderWidget(config);
      }),
    );
    btns.appendChild(
      mkBtn(b.text_manage || "Gestisci preferenze", false, function () {
        el.remove();
        openPref(config);
      }),
    );

    el.appendChild(txt);
    el.appendChild(btns);
    d.body.appendChild(el);
  }

  /* ── Floating widget ── */
  function renderWidget(config) {
    if (d.getElementById("suite-barrio-widget")) return;
    var b = (config && config.banner) || {};
    if (!b.show_widget) return;
    var wBtn = d.createElement("button");
    wBtn.id = "suite-barrio-widget";
    wBtn.type = "button";
    wBtn.setAttribute("aria-label", "Gestisci preferenze cookie");
    wBtn.title = "Gestisci preferenze cookie";
    var isRight = (b.widget_pos || "bottom-right") === "bottom-right";
    var wBg  = b.color_bg   || "#0D1B2E";
    var wFg  = b.color_text || "#FFFFFF";
    wBtn.style.cssText =
      "position:fixed;bottom:16px;" +
      (isRight ? "right:16px;" : "left:16px;") +
      "z-index:2147483644;width:44px;height:44px;border-radius:50%;border:none;cursor:pointer;" +
      "background:" + wBg + ";" +
      "box-shadow:0 2px 10px rgba(0,0,0,.25);transition:transform .15s;display:flex;align-items:center;justify-content:center;";
    wBtn.innerHTML =
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="' + wFg + '" aria-hidden="true">' +
        '<path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>' +
      '</svg>';
    wBtn.addEventListener("click", function () {
      loadConfig().then(openPref);
    });
    wBtn.addEventListener("mouseenter", function () {
      wBtn.style.transform = "scale(1.1)";
    });
    wBtn.addEventListener("mouseleave", function () {
      wBtn.style.transform = "scale(1)";
    });
    d.body.appendChild(wBtn);
  }

  /* ── Wire any [data-suite-barrio-preferences] widgets (present or dynamically injected) ── */
  function wireInlinePrefs(config) {
    var b  = (config && config.banner) || {};
    var bg = b.color_bg   || "#0D1B2E";
    var fg = b.color_text || "#FFFFFF";

    function applyToWidget(el) {
      wirePref(el, config);
      el.querySelectorAll(".suite-barrio-pref-btn-primary").forEach(function (btn) {
        btn.style.background = bg;
        btn.style.color = fg;
      });
    }

    // Widget già nel DOM al momento del boot
    d.querySelectorAll("[data-suite-barrio-preferences]").forEach(applyToWidget);

    // Widget inseriti dinamicamente (es. via fetch nel cookie report script)
    new MutationObserver(function (mutations) {
      mutations.forEach(function (m) {
        m.addedNodes.forEach(function (node) {
          if (node.nodeType !== 1) return;
          if (node.hasAttribute("data-suite-barrio-preferences")) {
            applyToWidget(node);
          }
          node.querySelectorAll("[data-suite-barrio-preferences]").forEach(applyToWidget);
        });
      });
    }).observe(d.body, { childList: true, subtree: true });
  }

  /* ── Bootstrap ── */
  function boot(config) {
    cfg = config;
    if (consent) {
      gtmUpdate(consent);
      if (d.readyState === "loading") {
        d.addEventListener("DOMContentLoaded", function () {
          activate(consent);
          renderWidget(config);
          wireInlinePrefs(config);
        });
      } else {
        activate(consent);
        renderWidget(config);
        wireInlinePrefs(config);
      }
    } else {
      if (d.readyState === "loading") {
        d.addEventListener("DOMContentLoaded", function () {
          renderBanner(config);
          wireInlinePrefs(config);
        });
      } else {
        renderBanner(config);
        wireInlinePrefs(config);
      }
    }
    emit("ready", consent ? Object.assign({}, consent) : null);
  }

  /* ── Public API ── */
  w.SuiteBarrioConsent = {
    getConsent: function () {
      return consent ? Object.assign({}, consent) : null;
    },
    hasConsent: function (cat) {
      return consent ? !!consent[cat] : false;
    },
    acceptAll: function () {
      save({
        necessary: true,
        functional: true,
        analytics: true,
        marketing: true,
      });
      var banner = d.getElementById("suite-barrio-banner");
      if (banner) banner.remove();
      loadConfig().then(renderWidget);
    },
    rejectAll: function () {
      save(defaults());
      var banner = d.getElementById("suite-barrio-banner");
      if (banner) banner.remove();
      loadConfig().then(renderWidget);
    },
    setConsent: function (prefs) {
      save(Object.assign(defaults(), prefs));
      var banner = d.getElementById("suite-barrio-banner");
      if (banner) banner.remove();
      loadConfig().then(renderWidget);
    },
    openPreferences: function () {
      loadConfig().then(openPref);
    },
    on: on,
  };

  loadConfig()
    .then(boot)
    .catch(function (e) {
      console.warn("[SuiteBarrioConsent] Config non disponibile:", e);
    });
})(window, document);
