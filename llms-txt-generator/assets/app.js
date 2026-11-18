/* LLMs.txt Generator — Frontend JS v3.2 */
jQuery(function ($) {

    var BTN_LABEL = 'Generar mi llms.txt gratis →';

    $('#llmstxt-lead-form').on('submit', function (e) {
        e.preventDefault();

        var name    = $('#llmstxt-name').val().trim();
        var email   = $('#llmstxt-email').val().trim();
        var website = $('#llmstxt-website').val().trim();
        var nap     = $('#llmstxt-nap').val().trim();
        var urls    = $('#llmstxt-urls').val().trim();

        if (!name || !email || !website || !nap || !urls) {
            showError('Por favor completa todos los campos requeridos.');
            return;
        }
        if (!isValidEmail(email)) {
            showError('Ingresa un email válido.');
            return;
        }
        if (!isValidUrl(website)) {
            showError('Ingresa una URL válida que empiece con https:// (ej: https://tusitio.com).');
            return;
        }

        hideError();

        var $btn = $('#llmstxt-submit-btn');
        $btn.prop('disabled', true).text('Enviando...');

        $.post(llmstxtAjax.ajax_url, {
            action:  'llmstxt_generate',
            nonce:   llmstxtAjax.nonce,
            name:    name,
            email:   email,
            website: website,
            nap:     nap,
            urls:    urls
        })
        .done(function (response) {
            if (response && response.success) {
                $('#llmstxt-success-msg').text(
                    'Estamos analizando tu sitio. Recibirás el archivo en ' +
                    response.data.email + ' en los próximos 1-3 minutos. Revisa también la carpeta de spam.'
                );
                $('#llmstxt-step-lead').hide();
                $('#llmstxt-step-success').show();
                $('html, body').animate({
                    scrollTop: $('#llmstxt-step-success').offset().top - 40
                }, 400);
            } else {
                showError(messageFrom(response) || 'Ocurrió un error. Intenta nuevamente.');
                resetButton($btn);
            }
        })
        .fail(function (xhr) {
            var msg = messageFrom(xhr.responseJSON);
            if (!msg && xhr.status === 403) {
                msg = 'La página lleva demasiado tiempo abierta. Recárgala e inténtalo de nuevo.';
            }
            showError(msg || 'Error de conexión. Revisa tu internet e intenta nuevamente.');
            resetButton($btn);
        });
    });

    function messageFrom(response) {
        return response && response.data && response.data.message ? response.data.message : '';
    }
    function resetButton($btn) {
        $btn.prop('disabled', false).text(BTN_LABEL);
    }
    function showError(msg) {
        $('#llmstxt-lead-error').text(msg).show();
    }
    function hideError() {
        $('#llmstxt-lead-error').hide();
    }
    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }
    function isValidUrl(url) {
        try {
            var u = new URL(url);
            return u.protocol === 'http:' || u.protocol === 'https:';
        } catch (_) {
            return false;
        }
    }

});
