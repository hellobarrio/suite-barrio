(function () {
  'use strict';

  var STORAGE_KEY = 'a11y_settings';
  var HTML  = document.documentElement;

  var STEP_LABELS = ['Molto ridotto', 'Ridotto', 'Predefinito', 'Grande', 'Molto grande'];
  var STEP_MIN = -2;
  var STEP_MAX =  2;

  var DEFAULTS = {
    zoom:          0,         // integer -2..+2
    textSize:      0,         // integer -2..+2
    lineHeight:    0,         // integer -2..+2
    letterSpacing: 0,         // integer -2..+2
    readableFont:  false,
    textAlign:    'default',  // 'default'|'center'|'left'|'right'
    contrast:     'none',     // 'none'|'dark'|'light'|'high'
    saturation:   'none',     // 'none'|'high'|'mono'|'low'
    muteSounds:    false,
    hideImages:    false,
    readingGuide:  false,
    stopAnimations:false,
    readingMask:   false,
    highlightHover:false,
    highlightFocus:false,
    cursor:       'default',  // 'default'|'black'|'white'
  };

  /* -- State -- */
  var state = loadState();

  /* -- PHP-injected config (declaration etc.) -- */
  var CFG = (typeof window.__suiteBarrioA11yConfig !== 'undefined') ? window.__suiteBarrioA11yConfig : {};

  function loadState() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (raw) return Object.assign({}, DEFAULTS, JSON.parse(raw));
    } catch (e) {}
    return Object.assign({}, DEFAULTS);
  }

  function saveState() {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
  }

  /* -- Apply state to <html> and body -- */
  function applyState() {
    /* Remove all a11y-- classes */
    var rem = [];
    HTML.classList.forEach(function (c) { if (c.indexOf('a11y--') === 0) rem.push(c); });
    rem.forEach(function (c) { HTML.classList.remove(c); });

    /* Text size */
    if (state.textSize !== 0) HTML.classList.add('a11y--text-' + state.textSize);

    /* Line height */
    if (state.lineHeight !== 0) HTML.classList.add('a11y--lh-' + state.lineHeight);

    /* Letter spacing */
    if (state.letterSpacing !== 0) HTML.classList.add('a11y--ls-' + state.letterSpacing);

    /* Zoom — applied as inline style on body (not a class) */
    if (document.body) {
      var zoomRatio = state.zoom !== 0 ? (1 + state.zoom * 0.12) : '';
      document.body.style.zoom = zoomRatio || '';
    }

    /* Readable font */
    if (state.readableFont) HTML.classList.add('a11y--readable-font');

    /* Text align */
    if (state.textAlign !== 'default') HTML.classList.add('a11y--align-' + state.textAlign);

    /* Contrast (mutually exclusive) */
    var contrastMap = { dark: 'dark-contrast', light: 'light-contrast', high: 'high-contrast' };
    if (contrastMap[state.contrast]) HTML.classList.add('a11y--' + contrastMap[state.contrast]);

    /* Saturation (mutually exclusive) */
    var satMap = { high: 'high-saturation', mono: 'monochrome', low: 'low-saturation' };
    if (satMap[state.saturation]) HTML.classList.add('a11y--' + satMap[state.saturation]);

    /* Orientation */
    if (state.stopAnimations) HTML.classList.add('a11y--reduce-motion');
    if (state.hideImages)     HTML.classList.add('a11y--hide-images');
    if (state.highlightHover) HTML.classList.add('a11y--highlight-hover');
    if (state.highlightFocus) HTML.classList.add('a11y--highlight-focus');
    if (state.readingGuide)   HTML.classList.add('a11y--reading-guide');
    if (state.readingMask)    HTML.classList.add('a11y--reading-mask');

    /* Cursor */
    if (state.cursor !== 'default') HTML.classList.add('a11y--cursor-' + state.cursor);

    /* Mute media */
    applyMuteSounds();
  }

  /* -- Mute sounds -- */
  function applyMuteSounds() {
    document.querySelectorAll('audio, video').forEach(function (el) {
      el.muted = !!state.muteSounds;
    });
  }

  /* -- Reading guide & mask -- */
  var guideEl, maskTop, maskBot;

  function ensureOverlays() {
    if (!guideEl) {
      guideEl = document.createElement('div');
      guideEl.id = 'a11y-reading-guide';
      document.body.appendChild(guideEl);
    }
    if (!maskTop) {
      maskTop = document.createElement('div');
      maskTop.id = 'a11y-mask-top';
      document.body.appendChild(maskTop);
      maskBot = document.createElement('div');
      maskBot.id = 'a11y-mask-bottom';
      document.body.appendChild(maskBot);
    }
  }

  function onMouseMove(e) {
    var y = e.clientY;
    if (state.readingGuide && guideEl) {
      guideEl.style.transform = 'translateY(' + y + 'px)';
    }
    if (state.readingMask && maskTop && maskBot) {
      var strip = 60;
      maskTop.style.top    = '0';
      maskTop.style.height = Math.max(0, y - strip / 2) + 'px';
      maskBot.style.top    = (y + strip / 2) + 'px';
      maskBot.style.height = (window.innerHeight - y - strip / 2) + 'px';
    }
  }

  /* -- SVG helpers -- */
  function svg(d, extra) {
    extra = extra || '';
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"'
      + ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round"'
      + ' stroke-linejoin="round" aria-hidden="true" focusable="false"' + extra + '>'
      + d + '</svg>';
  }
  function svgFill(d) {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"'
      + ' aria-hidden="true" focusable="false">' + d + '</svg>';
  }

  var ICONS = {
    zoom:          svg('<path d="M3 7h18M3 12h18M3 17h18"/><rect x="9" y="4" width="6" height="16" rx="1" fill="currentColor" opacity=".15"/>'),
    textSize:      svg('<text x="3" y="17" font-size="10" font-weight="700" fill="currentColor" stroke="none">A</text><text x="13" y="14" font-size="7" font-weight="700" fill="currentColor" stroke="none">↑</text>'),
    lineHeight:    svg('<line x1="4" y1="5" x2="20" y2="5"/><line x1="4" y1="10" x2="20" y2="10"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="4" y1="20" x2="20" y2="20"/><line x1="2" y1="5" x2="2" y2="20"/><polyline points="0,7 2,5 4,7" fill="currentColor"/><polyline points="0,18 2,20 4,18" fill="currentColor"/>'),
    letterSpacing: svg('<text x="3" y="16" font-size="9" font-weight="700" fill="currentColor" stroke="none">A</text><text x="11" y="16" font-size="9" font-weight="700" fill="currentColor" stroke="none">B</text><line x1="3" y1="19" x2="21" y2="19"/><line x1="3" y1="19" x2="3" y2="17"/><line x1="21" y1="19" x2="21" y2="17"/>'),
    readableFont:  svg('<text x="4" y="16" font-size="11" font-weight="700" fill="currentColor" stroke="none">Aa</text><line x1="4" y1="19" x2="20" y2="19"/>'),
    alignCenter:   svg('<line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="10" x2="18" y2="10"/><line x1="3" y1="14" x2="21" y2="14"/><line x1="6" y1="18" x2="18" y2="18"/>'),
    alignLeft:     svg('<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="10" x2="15" y2="10"/><line x1="3" y1="14" x2="21" y2="14"/><line x1="3" y1="18" x2="15" y2="18"/>'),
    alignRight:    svg('<line x1="3" y1="6" x2="21" y2="6"/><line x1="9" y1="10" x2="21" y2="10"/><line x1="3" y1="14" x2="21" y2="14"/><line x1="9" y1="18" x2="21" y2="18"/>'),
    darkContrast:  svg('<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>'),
    lightContrast: svg('<circle cx="12" cy="12" r="5"/><line x1="12" y1="2" x2="12" y2="4"/><line x1="12" y1="20" x2="12" y2="22"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="2" y1="12" x2="4" y2="12"/><line x1="20" y1="12" x2="22" y2="12"/>'),
    highContrast:  svgFill('<circle cx="12" cy="12" r="10" fill="#ddd"/><path d="M12 2a10 10 0 0 1 0 20z"/>'),
    highSat:       svgFill('<path d="M12 2C9 2 5 6 5 12a7 7 0 0 0 14 0c0-6-4-10-7-10z"/>'),
    monochrome:    svgFill('<path d="M12 2C9 2 5 6 5 12a7 7 0 0 0 14 0c0-6-4-10-7-10z" opacity=".25"/><path d="M12 2v20a7 7 0 0 0 0-14z" transform="translate(0,-5)"/><circle cx="12" cy="12" r="7" fill="none" stroke="currentColor" stroke-width="2"/>'),
    lowSat:        svgFill('<path d="M12 2C9 2 5 6 5 12a7 7 0 0 0 14 0c0-6-4-10-7-10z" opacity=".45"/>'),
    mute:          svg('<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/>'),
    hideImg:       svg('<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21,15 16,10 5,21"/><line x1="3" y1="3" x2="21" y2="21"/>'),
    readGuide:     svg('<line x1="2" y1="12" x2="22" y2="12"/><line x1="12" y1="2" x2="12" y2="8"/><line x1="12" y1="16" x2="12" y2="22" stroke-dasharray="2 2"/>'),
    stopAnim:      svg('<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/><line x1="2" y1="2" x2="22" y2="22"/>'),
    readMask:      svg('<rect x="2" y="2" width="20" height="6" rx="1" fill="currentColor" opacity=".3"/><rect x="2" y="16" width="20" height="6" rx="1" fill="currentColor" opacity=".3"/><line x1="2" y1="12" x2="22" y2="12"/>'),
    hlHover:       svg('<polyline points="4 20 4 4 20 4"/><path d="M10 4L20 14"/><circle cx="15" cy="15" r="4"/>'),
    hlFocus:       svg('<circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="7" stroke-dasharray="2 3"/><circle cx="12" cy="12" r="11" stroke-dasharray="1 3"/>'),
    cursorBlack:   svgFill('<path d="M4 3l16 10-7 1 3 8-2 1-3-8-4 3z"/>'),
    cursorWhite:   svgFill('<path d="M4 3l16 10-7 1 3 8-2 1-3-8-4 3z" stroke="currentColor" stroke-width="1.5" fill="none"/>'),
    close:         svg('<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>'),
    reset:         svg('<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4"/>'),
    person:        svgFill('<circle cx="12" cy="4.5" r="2"/><path d="M19 9.5H5a.5.5 0 0 0 0 1h5.2L8.4 18a.5.5 0 0 0 1 .2l2.6-6.5 2.6 6.5a.5.5 0 0 0 1-.2l-1.8-7.5H19a.5.5 0 0 0 0-1z"/>'),
    decl:          svg('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>'),
    back:          svg('<polyline points="15 18 9 12 15 6"/>'),
  };

  /* -- Step label -- */
  function stepLabel(val) {
    return STEP_LABELS[val - STEP_MIN];
  }

  /* -- Build stepper card -- */
  function stepperCard(id, icon, label) {
    var val = state[id];
    return '<div class="a11y-card a11y-card--stepper" role="group" aria-label="' + label + '">'
      + icon
      + '<span class="a11y-card__label">' + label + '</span>'
      + '<div class="a11y-stepper">'
      +   '<button class="a11y-stepper__btn" type="button" data-step="' + id + '" data-dir="-1"'
      +     ' aria-label="Riduci ' + label + '"'
      +     (val <= STEP_MIN ? ' disabled' : '') + '>'
      +     '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3"><line x1="5" y1="12" x2="19" y2="12"/></svg>'
      +   '</button>'
      +   '<span class="a11y-stepper__val" data-step-val="' + id + '">' + stepLabel(val) + '</span>'
      +   '<button class="a11y-stepper__btn" type="button" data-step="' + id + '" data-dir="1"'
      +     ' aria-label="Aumenta ' + label + '"'
      +     (val >= STEP_MAX ? ' disabled' : '') + '>'
      +     '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>'
      +   '</button>'
      + '</div>'
      + '</div>';
  }

  /* -- Build toggle card -- */
  function toggleCard(key, icon, label, pressed) {
    return '<button class="a11y-card" type="button"'
      + ' data-a11y-key="' + key + '"'
      + ' aria-pressed="' + (pressed ? 'true' : 'false') + '"'
      + ' aria-label="' + label + '">'
      + icon
      + '<span>' + label + '</span>'
      + '</button>';
  }

  /* -- Build widget HTML -- */
  function buildHTML() {
    var s = state;

    return ''
      + '<div class="a11y-widget__panel" id="a11y-panel"'
      + ' role="dialog" aria-labelledby="a11y-panel-title" hidden>'

      /* Header */
      + '<div class="a11y-widget__header">'
      +   '<div class="a11y-widget__header-top">'
      +     '<button class="a11y-widget__close" type="button" aria-label="Chiudi impostazioni accessibilità">' + ICONS.close + '</button>'
      +     '<div class="a11y-widget__title" id="a11y-panel-title" role="heading" aria-level="2">Regolazioni per l\'accessibilità</div>'
      +     '<div style="width:28px;flex-shrink:0"></div>'
      +   '</div>'
      +   '<div class="a11y-widget__header-actions" id="a11y-header-actions">'
      +     '<button class="a11y-header-btn" id="a11y-reset-btn" type="button">' + ICONS.reset + 'Reset impostaz.</button>'
      +     (CFG.declaration && CFG.declaration.enabled ? '<button class="a11y-header-btn" id="a11y-decl-btn" type="button">' + ICONS.decl + 'Dichiarazione</button>' : '')
      +   '</div>'
      + '</div>'

      /* Body */
      + '<div class="a11y-widget__body" id="a11y-main-body" data-scroll="prevent">'

      /* Section: Contenuti */
      + '<div class="a11y-section">'
      +   '<div class="a11y-section__title">Regolazioni dei contenuti</div>'
      +   '<div class="a11y-grid-2">'
      +     stepperCard('zoom',          ICONS.zoom,         'Scala i contenuti')
      +     toggleCard('readableFont',   ICONS.readableFont, 'Carattere leggibile', s.readableFont)
      +     stepperCard('textSize',      ICONS.textSize,     'Dimensionamento caratteri')
      +     toggleCard('textAlign:center', ICONS.alignCenter, 'Allinea al centro', s.textAlign === 'center')
      +     stepperCard('lineHeight',    ICONS.lineHeight,   'Altezza della riga')
      +     toggleCard('textAlign:left', ICONS.alignLeft,    'Allinea a sinistra', s.textAlign === 'left')
      +     stepperCard('letterSpacing', ICONS.letterSpacing,'Spaziatura lettere')
      +     toggleCard('textAlign:right',ICONS.alignRight,   'Allinea a destra',   s.textAlign === 'right')
      +   '</div>'
      + '</div>'

      /* Section: Colore */
      + '<div class="a11y-section">'
      +   '<div class="a11y-section__title">Regolazioni del colore</div>'
      +   '<div class="a11y-grid-3">'
      +     toggleCard('contrast:dark',  ICONS.darkContrast,  'Contrasto scuro',    s.contrast === 'dark')
      +     toggleCard('contrast:light', ICONS.lightContrast, 'Contrasto chiaro',   s.contrast === 'light')
      +     toggleCard('contrast:high',  ICONS.highContrast,  'Contrasto elevato',  s.contrast === 'high')
      +     toggleCard('saturation:high',ICONS.highSat,       'Saturazione elevata',s.saturation === 'high')
      +     toggleCard('saturation:mono',ICONS.monochrome,    'Monocromatico',      s.saturation === 'mono')
      +     toggleCard('saturation:low', ICONS.lowSat,        'Saturazione bassa',  s.saturation === 'low')
      +   '</div>'
      + '</div>'

      /* Section: Orientamento */
      + '<div class="a11y-section">'
      +   '<div class="a11y-section__title">Regolazioni dell\'orientamento</div>'
      +   '<div class="a11y-grid-3">'
      +     toggleCard('muteSounds',     ICONS.mute,      'Disattiva suoni',       s.muteSounds)
      +     toggleCard('hideImages',     ICONS.hideImg,   'Nascondi immagini',     s.hideImages)
      +     toggleCard('readingGuide',   ICONS.readGuide, 'Guida alla lettura',    s.readingGuide)
      +     toggleCard('stopAnimations', ICONS.stopAnim,  'Ferma animazioni',      s.stopAnimations)
      +     toggleCard('readingMask',    ICONS.readMask,  'Maschera di lettura',   s.readingMask)
      +     toggleCard('highlightHover', ICONS.hlHover,   'Evidenzia mouse hover', s.highlightHover)
      +     toggleCard('highlightFocus', ICONS.hlFocus,   'Evidenzia messa a fuoco', s.highlightFocus)
      +     toggleCard('cursor:black',   ICONS.cursorBlack,'Grande cursore nero',  s.cursor === 'black')
      +     toggleCard('cursor:white',   ICONS.cursorWhite,'Grande cursore bianco',s.cursor === 'white')
      +   '</div>'
      + '</div>'

      + '</div>' /* end body */

      /* Footer */
      + '<div class="a11y-widget__footer" id="a11y-main-footer">'
      +   '<span class="a11y-brand">Accessibilità web</span>'
      + '</div>'

      /* Declaration view (hidden by default) */
      + (CFG.declaration && CFG.declaration.enabled
          ? '<div class="a11y-decl-view" id="a11y-decl-view" hidden>'
          +   '<div class="a11y-decl-back">'
          +     '<button class="a11y-header-btn" id="a11y-back-btn" type="button">' + ICONS.back + 'Torna indietro</button>'
          +   '</div>'
          +   '<div class="a11y-decl-body" data-scroll="prevent">'
          +     '<div class="a11y-decl-content">' + (CFG.declaration.content || '') + '</div>'
          +   '</div>'
          + '</div>'
          : '')

      + '</div>' /* end panel */

      /* Trigger */
      + '<button class="a11y-widget__toggle" id="a11y-toggle" type="button"'
      + ' aria-expanded="false" aria-controls="a11y-panel"'
      + ' aria-label="Impostazioni di accessibilità">'
      + ICONS.person
      + '</button>';
  }

  /* -- Open / close -- */
  function openPanel(toggle, panel) {
    panel.removeAttribute('hidden');
    panel.classList.add('a11y-opening');
    toggle.setAttribute('aria-expanded', 'true');
    var closeBtn = panel.querySelector('.a11y-widget__close');
    if (closeBtn) closeBtn.focus();
  }

  function closePanel(toggle, panel) {
    panel.setAttribute('hidden', '');
    panel.classList.remove('a11y-opening');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.focus();
  }

  /* -- Handle toggle card click -- */
  function handleToggleKey(key, panel) {
    var parts = key.split(':');
    if (parts.length === 2) {
      var group = parts[0];
      var value = parts[1];
      if (state[group] === value) {
        state[group] = group === 'contrast' || group === 'saturation' ? 'none' : 'default';
      } else {
        state[group] = value;
      }
    } else {
      state[key] = !state[key];
    }
    saveState();
    applyState();
    syncCardUI(panel);
  }

  /* -- Sync card pressed states -- */
  function syncCardUI(panel) {
    panel.querySelectorAll('[data-a11y-key]').forEach(function (btn) {
      var key = btn.getAttribute('data-a11y-key');
      var parts = key.split(':');
      var pressed;
      if (parts.length === 2) {
        pressed = state[parts[0]] === parts[1];
      } else {
        pressed = !!state[key];
      }
      btn.setAttribute('aria-pressed', pressed ? 'true' : 'false');
    });
  }

  /* -- Sync stepper UI -- */
  function syncStepperUI(panel) {
    panel.querySelectorAll('[data-step-val]').forEach(function (el) {
      var id = el.getAttribute('data-step-val');
      el.textContent = stepLabel(state[id]);
    });
    panel.querySelectorAll('[data-step][data-dir]').forEach(function (btn) {
      var id  = btn.getAttribute('data-step');
      var dir = parseInt(btn.getAttribute('data-dir'), 10);
      btn.disabled = dir < 0 ? state[id] <= STEP_MIN : state[id] >= STEP_MAX;
    });
  }

  /* -- Reset all -- */
  function resetAll(panel) {
    state = Object.assign({}, DEFAULTS);
    saveState();
    applyState();
    syncCardUI(panel);
    syncStepperUI(panel);
  }

  /* -- Init -- */
  function init() {
    var widget = document.createElement('div');
    widget.id = 'a11y-widget';
    widget.className = 'a11y-widget';
    widget.innerHTML = buildHTML();
    document.body.appendChild(widget);

    var toggle = document.getElementById('a11y-toggle');
    var panel  = document.getElementById('a11y-panel');

    toggle.addEventListener('click', function () {
      panel.hasAttribute('hidden') ? openPanel(toggle, panel) : closePanel(toggle, panel);
    });

    panel.querySelector('.a11y-widget__close').addEventListener('click', function () {
      closePanel(toggle, panel);
    });

    document.getElementById('a11y-reset-btn').addEventListener('click', function () {
      resetAll(panel);
    });

    var declBtn = document.getElementById('a11y-decl-btn');
    var backBtn = document.getElementById('a11y-back-btn');
    if (declBtn) {
      declBtn.addEventListener('click', function () {
        document.getElementById('a11y-header-actions').setAttribute('hidden', '');
        document.getElementById('a11y-main-body').setAttribute('hidden', '');
        document.getElementById('a11y-main-footer').setAttribute('hidden', '');
        document.getElementById('a11y-decl-view').removeAttribute('hidden');
        document.getElementById('a11y-panel-title').textContent = 'Dichiarazione di Accessibilità';
        backBtn.focus();
      });
    }
    if (backBtn) {
      backBtn.addEventListener('click', function () {
        document.getElementById('a11y-header-actions').removeAttribute('hidden');
        document.getElementById('a11y-main-body').removeAttribute('hidden');
        document.getElementById('a11y-main-footer').removeAttribute('hidden');
        document.getElementById('a11y-decl-view').setAttribute('hidden', '');
        document.getElementById('a11y-panel-title').textContent = 'Regolazioni per l\'accessibilità';
        declBtn.focus();
      });
    }

    document.addEventListener('keydown', function (e) {
      if ((e.key === 'Escape' || e.key === 'Esc') && !panel.hasAttribute('hidden')) {
        closePanel(toggle, panel);
      }
    });

    document.addEventListener('pointerdown', function (e) {
      if (!panel.hasAttribute('hidden') && !widget.contains(e.target)) {
        closePanel(toggle, panel);
      }
    });

    panel.querySelectorAll('[data-a11y-key]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        handleToggleKey(this.getAttribute('data-a11y-key'), panel);
      });
    });

    panel.querySelectorAll('[data-step][data-dir]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id  = this.getAttribute('data-step');
        var dir = parseInt(this.getAttribute('data-dir'), 10);
        var newVal = Math.min(STEP_MAX, Math.max(STEP_MIN, state[id] + dir));
        if (newVal === state[id]) return;
        state[id] = newVal;
        saveState();
        applyState();
        syncStepperUI(panel);
      });
    });

    ensureOverlays();
    document.addEventListener('mousemove', onMouseMove, { passive: true });
  }

  applyState();

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  if (typeof MutationObserver !== 'undefined') {
    var mo = new MutationObserver(function () { if (state.muteSounds) applyMuteSounds(); });
    document.addEventListener('DOMContentLoaded', function () {
      mo.observe(document.body, { childList: true, subtree: true });
    });
  }

}());
