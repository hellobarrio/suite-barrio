/* Suite Barrio — Auto Alt: pulsante "Genera con AI" nella pagina dettaglio media */
(function () {
    if (window._suiteBarrioGenBound) return;
    window._suiteBarrioGenBound = true;

    var NONCE    = (typeof suiteBarrioAutoAlt !== 'undefined') ? suiteBarrioAutoAlt.nonce   : '';
    var AJAX_URL = (typeof suiteBarrioAutoAlt !== 'undefined') ? suiteBarrioAutoAlt.ajaxUrl : '';

    if (!document.getElementById('suite-barrio-gen-spin-style')) {
        var st = document.createElement('style');
        st.id  = 'suite-barrio-gen-spin-style';
        st.textContent =
            '@keyframes suiteBarrioGenSpin { to { transform: rotate(360deg); } }' +
            '.suite-barrio-gen-spinner {' +
            '  display: inline-block;' +
            '  width: 13px; height: 13px;' +
            '  border: 2px solid rgba(0,0,0,.15);' +
            '  border-top-color: #2271b1;' +
            '  border-radius: 50%;' +
            '  animation: suiteBarrioGenSpin .7s linear infinite;' +
            '  vertical-align: middle;' +
            '  margin-right: 5px;' +
            '}';
        document.head.appendChild(st);
    }

    document.addEventListener('click', async function (e) {
        var btn = e.target.closest('.suite-barrio-gen-btn');
        if (!btn || btn.disabled) return;

        var id   = btn.dataset.id;
        var wrap = btn.closest('td') || btn.parentNode;
        var msg  = wrap ? wrap.querySelector('.suite-barrio-gen-result') : null;

        btn.disabled  = true;
        btn.innerHTML = '<span class="suite-barrio-gen-spinner"></span>Generazione in corso...';
        if (msg) { msg.style.color = '#646970'; msg.textContent = ''; }

        try {
            var body = new URLSearchParams({
                action        : 'suite_barrio_generate_force',
                nonce         : NONCE,
                attachment_id : id
            });

            var res  = await fetch(AJAX_URL, { method: 'POST', body: body });
            var data = await res.json();

            if (!data.success) throw new Error(data.data || 'Errore sconosciuto');

            btn.innerHTML = 'Generato!';
            if (msg) { msg.style.color = '#00a32a'; msg.textContent = 'Aggiornamento...'; }

            location.reload();

        } catch (err) {
            btn.innerHTML = 'Genera con AI';
            btn.disabled  = false;
            if (msg) {
                msg.style.color = '#d63638';
                msg.textContent = 'Errore: ' + err.message;
            }
        }
    });
})();
