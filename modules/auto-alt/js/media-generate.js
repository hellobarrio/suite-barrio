/* WP Barrio — Auto Alt: pulsante "Genera con AI" nella pagina dettaglio media */
(function () {
    if (window._wpbGenBound) return;
    window._wpbGenBound = true;

    var NONCE    = (typeof wpbAutoAlt !== 'undefined') ? wpbAutoAlt.nonce   : '';
    var AJAX_URL = (typeof wpbAutoAlt !== 'undefined') ? wpbAutoAlt.ajaxUrl : '';

    if (!document.getElementById('wpb-gen-spin-style')) {
        var st = document.createElement('style');
        st.id  = 'wpb-gen-spin-style';
        st.textContent =
            '@keyframes wpbGenSpin { to { transform: rotate(360deg); } }' +
            '.wpb-gen-spinner {' +
            '  display: inline-block;' +
            '  width: 13px; height: 13px;' +
            '  border: 2px solid rgba(0,0,0,.15);' +
            '  border-top-color: #2271b1;' +
            '  border-radius: 50%;' +
            '  animation: wpbGenSpin .7s linear infinite;' +
            '  vertical-align: middle;' +
            '  margin-right: 5px;' +
            '}';
        document.head.appendChild(st);
    }

    document.addEventListener('click', async function (e) {
        var btn = e.target.closest('.wpb-gen-btn');
        if (!btn || btn.disabled) return;

        var id   = btn.dataset.id;
        var wrap = btn.closest('td') || btn.parentNode;
        var msg  = wrap ? wrap.querySelector('.wpb-gen-result') : null;

        btn.disabled  = true;
        btn.innerHTML = '<span class="wpb-gen-spinner"></span>Generazione in corso...';
        if (msg) { msg.style.color = '#646970'; msg.textContent = ''; }

        try {
            var body = new URLSearchParams({
                action        : 'wp_alt_ai_generate_force',
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
