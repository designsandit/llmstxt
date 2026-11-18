/* LLMs.txt Generator — Frontend JS v3.0 */
jQuery(function ($) {

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
            showError('Ingresa una URL válida (ej: https://tusitio.com).');
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
            urls:    urls,
        })
        .done(function (response) {
            if (response.success) {
                $('#llmstxt-success-msg').text(
                    'Estamos analizando tu sitio. Recibirás el archivo en ' +
                    response.data.email + ' en los próximos 1-3 minutos.'
                );
                $('#llmstxt-step-lead').hide();
                $('#llmstxt-step-success').show();
                $('html, body').animate({
                    scrollTop: $('#llmstxt-step-success').offset().top - 40
                }, 400);
            } else {
                showError(response.data.message || 'Ocurrió un error. Intenta nuevamente.');
                $btn.prop('disabled', false).text('Generar mi llms.txt gratis →');
            }
        })
        .fail(function () {
            showError('Error de conexión. Revisa tu internet e intenta nuevamente.');
            $btn.prop('disabled', false).text('Generar mi llms.txt gratis →');
        });
    });

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
        try { new URL(url); return true; } catch (_) { return false; }
    }

});
