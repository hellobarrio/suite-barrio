/* Suite Barrio — Auto Alt: generazione massiva dalla libreria media (solo lista) */
(function () {
    if (window._suiteBarrioBulkBound) return;
    window._suiteBarrioBulkBound = true;

    var NONCE    = (typeof suiteBarrioAutoAlt !== 'undefined') ? suiteBarrioAutoAlt.nonce   : '';
    var AJAX_URL = (typeof suiteBarrioAutoAlt !== 'undefined') ? suiteBarrioAutoAlt.ajaxUrl : '';

    /* ══ CSS overlay ══════════════════════════════════════ */
    if (!document.getElementById('suite-barrio-bulk-style')) {
        var st = document.createElement('style');
        st.id  = 'suite-barrio-bulk-style';
        st.textContent =
            '@keyframes suiteBarrioBulkSpin { to { transform: rotate(360deg); } }' +
            '#suite-barrio-bulk-overlay {' +
            '  position:fixed;inset:0;z-index:999999;' +
            '  background:rgba(0,0,0,.55);' +
            '  display:flex;align-items:center;justify-content:center;' +
            '}' +
            '#suite-barrio-bulk-box {' +
            '  background:#fff;border-radius:4px;padding:32px 36px;' +
            '  min-width:340px;max-width:460px;' +
            '  box-shadow:0 8px 32px rgba(0,0,0,.22);text-align:center;' +
            '}' +
            '#suite-barrio-bulk-box h3 { margin:0 0 6px;font-size:16px;color:#1d2327; }' +
            '#suite-barrio-bulk-box p  { margin:0 0 20px;font-size:13px;color:#646970; }' +
            '#suite-barrio-bulk-track { background:#f0f0f1;border-radius:3px;height:6px;overflow:hidden;margin-bottom:10px; }' +
            '#suite-barrio-bulk-bar   { height:100%;width:0%;background:#2271b1;transition:width .3s;border-radius:3px; }' +
            '#suite-barrio-bulk-bar.done  { background:#00a32a; }' +
            '#suite-barrio-bulk-bar.error { background:#d63638; }' +
            '#suite-barrio-bulk-label { font-size:12px;color:#646970;margin-bottom:20px; }' +
            '#suite-barrio-bulk-spinner {' +
            '  display:inline-block;width:18px;height:18px;' +
            '  border:3px solid rgba(34,113,177,.2);border-top-color:#2271b1;' +
            '  border-radius:50%;animation:suiteBarrioBulkSpin .7s linear infinite;margin-bottom:14px;' +
            '}' +
            '#suite-barrio-bulk-close {' +
            '  display:none;margin-top:4px;padding:6px 20px;' +
            '  background:#2271b1;color:#fff;border:none;border-radius:3px;' +
            '  cursor:pointer;font-size:13px;font-family:inherit;' +
            '}';
        document.head.appendChild(st);
    }

    /* ══ OVERLAY ══════════════════════════════════════════ */
    function getOverlay() {
        var el = document.getElementById('suite-barrio-bulk-overlay');
        if (el) return el;
        el = document.createElement('div');
        el.id = 'suite-barrio-bulk-overlay';
        el.style.display = 'none';
        el.innerHTML =
            '<div id="suite-barrio-bulk-box">' +
            '<div id="suite-barrio-bulk-spinner"></div>' +
            '<h3>Generazione Alt + Title in corso</h3>' +
            '<p id="suite-barrio-bulk-sub">Elaborazione immagini selezionate...</p>' +
            '<div id="suite-barrio-bulk-track"><div id="suite-barrio-bulk-bar"></div></div>' +
            '<div id="suite-barrio-bulk-label">0 / 0</div>' +
            '<button id="suite-barrio-bulk-close">Chiudi</button>' +
            '</div>';
        document.body.appendChild(el);
        document.getElementById('suite-barrio-bulk-close').addEventListener('click', function () {
            el.style.display = 'none';
            location.reload();
        });
        return el;
    }

    function updateProgress(done, errors, total) {
        var pct = total > 0 ? Math.round(((done + errors) / total) * 100) : 0;
        document.getElementById('suite-barrio-bulk-bar').style.width = pct + '%';
        document.getElementById('suite-barrio-bulk-label').textContent =
            (done + errors) + ' / ' + total +
            (errors > 0 ? '  (' + errors + ' errori)' : '');
    }

    /* ══ PROCESSA IDs ═════════════════════════════════════ */
    async function processIds(ids) {
        var overlay  = getOverlay();
        var bar      = document.getElementById('suite-barrio-bulk-bar');
        var sub      = document.getElementById('suite-barrio-bulk-sub');
        var spinner  = document.getElementById('suite-barrio-bulk-spinner');
        var closeBtn = document.getElementById('suite-barrio-bulk-close');

        overlay.style.display  = 'flex';
        bar.className          = '';
        bar.style.width        = '0%';
        closeBtn.style.display = 'none';
        spinner.style.display  = 'inline-block';

        var total = ids.length, done = 0, errors = 0;
        updateProgress(0, 0, total);

        for (var i = 0; i < ids.length; i++) {
            sub.textContent = 'Elaborazione ' + (i + 1) + ' di ' + total + '...';
            try {
                var body = new URLSearchParams({
                    action: 'suite_barrio_generate_force',
                    nonce: NONCE,
                    attachment_id: ids[i]
                });
                var res  = await fetch(AJAX_URL, { method: 'POST', body: body });
                var data = await res.json();
                if (data.success) { done++; } else { errors++; }
            } catch (_) { errors++; }
            updateProgress(done, errors, total);
            if (i < ids.length - 1) await new Promise(function (r) { setTimeout(r, 400); });
        }

        spinner.style.display  = 'none';
        bar.style.width        = '100%';
        bar.className          = (errors === total) ? 'error' : 'done';
        sub.textContent        = errors === 0
            ? 'Completato! ' + done + ' immagini aggiornate.'
            : done + ' aggiornate, ' + errors + ' errori.';
        closeBtn.style.display = 'inline-block';
    }

    /* ══ LISTA: intercetta form bulk standard ═════════════ */
    function init() {
        document.querySelectorAll('#bulk-action-form, form#posts-filter').forEach(function (form) {
            form.addEventListener('submit', async function (e) {
                var sel1   = form.querySelector('select[name="action"]');
                var sel2   = form.querySelector('select[name="action2"]');
                var action = (sel1 && sel1.value !== '-1') ? sel1.value
                           : (sel2 && sel2.value !== '-1') ? sel2.value : '-1';
                if (action !== 'suite_barrio_generate_alt') return;
                e.preventDefault();
                var ids = [];
                form.querySelectorAll('input[name="media[]"]:checked, input[name="post[]"]:checked')
                    .forEach(function (cb) { if (cb.value) ids.push(cb.value); });
                if (!ids.length) { alert('Seleziona almeno un\'immagine.'); return; }
                await processIds(ids);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
