// =========================================================
// WORKING OVERLAY - USO GLOBAL
// =========================================================
(function () {

    const overlay = () => document.getElementById('workingOverlay');
    const message = () => document.getElementById('workingMessage');

    window.Working = {

        show: function (msg = 'Trabajando...') {
            if (!overlay()) return;

            message().innerText = msg;
            overlay().style.display = 'flex';

            // Evita scroll
            document.body.style.overflow = 'hidden';
        },

        hide: function () {
            if (!overlay()) return;

            overlay().style.display = 'none';
            document.body.style.overflow = '';
        }
    };

})();