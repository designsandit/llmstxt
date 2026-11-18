/* LLMs.txt Generator — Frontend v3.6: asistente en 3 pasos */
jQuery(function ($) {

    var $app = $('#llmstxt-app');
    if (!$app.length || typeof llmstxtAjax === 'undefined') return;

    var MAX = parseInt(llmstxtAjax.max_urls, 10) || 20;
    var SUGGESTED = ['/servicios', '/nosotros', '/contacto', '/blog'];
    var STEP_FIELDS = { 1: ['name', 'email'], 2: ['domain', 'biz', 'address', 'city', 'phone', 'profiles'], 3: ['urls'] };
    var HINTS = { 1: 'Paso 1 de 3 · menos de 1 minuto' };
    var state = { step: 1, urls: [] };

    var $form  = $app.find('#llmstxt-lead-form');
    var $alert = $app.find('.llms-alert');
    var $next  = $app.find('.llms-next');
    var $back  = $app.find('.llms-back');

    // ─── Utilidades ───────────────────────────────────────────────────────
    function val(id) { return $.trim($('#llms-' + id).val() || ''); }

    function host() {
        var v = val('domain').replace(/^https?:\/\//i, '');
        if (!v) return '';
        try {
            var u = new URL('https://' + v);
            return u.hostname.indexOf('.') > 0 ? u.hostname : '';
        } catch (e) { return ''; }
    }

    function normalize(raw) {
        var v = $.trim(raw || '');
        if (!v) return null;
        if (v.charAt(0) === '/') {
            if (!host()) return null;
            v = 'https://' + host() + v;
        } else if (!/^https?:\/\//i.test(v)) {
            v = 'https://' + v;
        }
        try {
            var u = new URL(v);
            if (u.hostname.indexOf('.') < 1) return null;
            var path = u.pathname === '/' ? '' : u.pathname.replace(/\/$/, '');
            return u.protocol + '//' + u.hostname + path + u.search;
        } catch (e) { return null; }
    }

    // Perfiles opcionales: uno por línea; se acepta sin https://. Devuelve [válidos, inválidos].
    function profiles() {
        var ok = [], bad = [];
        $.each(($('#llms-profiles').val() || '').split(/[\s,]+/), function (_, raw) {
            raw = $.trim(raw);
            if (!raw) return;
            var v = /^https?:\/\//i.test(raw) ? raw : 'https://' + raw;
            try {
                var u = new URL(v);
                if (u.hostname.indexOf('.') > 0) { if (ok.indexOf(v) < 0) ok.push(v); return; }
            } catch (e) { /* inválido */ }
            bad.push(raw);
        });
        return [ok, bad];
    }

    function pretty(u) { return u.replace(/^https?:\/\//, '').replace(/^www\./, ''); }

    function setError(field, msg) {
        var $input = field === 'urls' ? $('#llms-url') : $('#llms-' + field);
        var $err = $('#llms-err-' + field);
        if (msg) {
            $err.text(msg).prop('hidden', false);
            $input.attr('aria-invalid', 'true').addClass('is-invalid');
        } else {
            $err.text('').prop('hidden', true);
            $input.removeAttr('aria-invalid').removeClass('is-invalid');
        }
    }

    function showAlert(msg) { $alert.text(msg || '').prop('hidden', !msg); }

    // Igual que reveal(), pero centrado en un campo: deja visible su etiqueta y no lo esconde bajo la cabecera.
    function revealField($el) {
        if (!$el.length) return;
        var el = $el[0];
        try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); }
        var r = el.getBoundingClientRect();
        var offset = Math.min(160, window.innerHeight * 0.2) + 40;
        if (r.top < offset || r.bottom > window.innerHeight - 24) {
            var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            window.scrollTo({ top: window.pageYOffset + r.top - offset, behavior: reduce ? 'auto' : 'smooth' });
        }
    }

    // Enfoca sin que el navegador lleve el elemento al borde superior (donde suele haber una cabecera fija)
    // y desplaza la tarjeta solo si no está bien a la vista.
    function reveal($el) {
        if (!$el.length) return;
        var el = $el[0];
        try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); }
        var card = $app.find('.llms-card')[0];
        var top = card.getBoundingClientRect().top;
        var offset = Math.min(160, window.innerHeight * 0.2);
        if (top < offset || top > window.innerHeight * 0.5) {
            var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            window.scrollTo({ top: window.pageYOffset + top - offset, behavior: reduce ? 'auto' : 'smooth' });
        }
    }

    // ─── Validación por paso ──────────────────────────────────────────────
    function errorsFor(step) {
        var e = {};
        if (step === 1) {
            if (!val('name')) e.name = 'Escribe tu nombre.';
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(val('email'))) e.email = 'Revisa tu email, por ejemplo: juan@tuempresa.com';
        }
        if (step === 2) {
            if (!host()) e.domain = 'Escribe tu dominio, por ejemplo: tuempresa.com';
            if (!val('biz')) e.biz = '¿Cómo se llama tu negocio?';
            if (!val('address')) e.address = 'Añade la dirección tal como sale en Google.';
            if (!val('city')) e.city = '¿En qué ciudad está tu negocio?';
            if (val('phone').replace(/\D/g, '').length < 7) e.phone = 'Añade un teléfono con indicativo.';
            var pr = profiles();
            if (pr[1].length) e.profiles = 'Revisa este enlace: ' + pr[1].join(', ');
            else if (pr[0].length > 10) e.profiles = 'Máximo 10 perfiles.';
        }
        if (step === 3 && !state.urls.length) e.urls = 'Añade al menos una página.';
        return e;
    }

    function validate(step) {
        var e = errorsFor(step);
        var first = null;
        $.each(STEP_FIELDS[step], function (_, f) {
            setError(f, e[f] || '');
            if (e[f] && !first) first = f;
        });
        if (first) revealField($(first === 'urls' ? '#llms-url' : '#llms-' + first));
        return !first;
    }

    // ─── Navegación entre pasos ───────────────────────────────────────────
    function goTo(step, focusHeading) {
        state.step = step;
        $app.find('.llms-panel').each(function () {
            $(this).prop('hidden', parseInt($(this).data('panel'), 10) !== step);
        });
        $app.find('.llms-step').each(function () {
            var n = parseInt($(this).data('step'), 10);
            $(this).toggleClass('is-current', n === step).toggleClass('is-done', n < step);
            if (n === step) $(this).attr('aria-current', 'step'); else $(this).removeAttr('aria-current');
        });
        $back.prop('hidden', step === 1);
        // El texto de ayuda solo en el paso 1: en los demás el indicador de arriba ya dice el paso
        // y así "Atrás" y el botón principal caben en una fila.
        $app.find('.llms-hint').text(HINTS[step]).prop('hidden', step !== 1);
        $next.text(step < 3 ? 'Siguiente →' : '¡Generar mi llms.txt!');
        showAlert('');
        if (step === 3 && !state.urls.length && host()) state.urls = ['https://' + host()];
        if (step === 3) renderUrls();
        if (focusHeading !== false) reveal($app.find('.llms-panel[data-panel="' + step + '"] .llms-h'));
    }

    // ─── Páginas (etiquetas) ──────────────────────────────────────────────
    function addUrls(text) {
        var parts = (text || '').split(/[\s,]+/).filter(Boolean);
        var bad = [];
        var over = false;
        $.each(parts, function (_, p) {
            var n = normalize(p);
            if (!n) { bad.push(p); return; }
            if (state.urls.indexOf(n) >= 0) return;
            if (state.urls.length >= MAX) { over = true; return; }
            state.urls.push(n);
        });
        $('#llms-url').val(bad.join(' '));
        setError('urls', bad.length ? 'Esto no parece una página válida: ' + bad.join(', ')
            : (over ? 'Máximo ' + MAX + ' páginas: quita alguna para añadir otra.' : ''));
        renderUrls();
    }

    function renderUrls() {
        var $list = $app.find('.llms-chips').empty();
        $.each(state.urls, function (i, u) {
            var $li = $('<li/>');
            $('<span class="llms-chip-text"/>').text(pretty(u)).attr('title', u).appendTo($li);
            $('<button type="button" class="llms-chip-x"/>')
                .attr('aria-label', 'Quitar ' + pretty(u))
                .html('<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>')
                .on('click', function () {
                    state.urls.splice(i, 1);
                    renderUrls();
                    $('#llms-url').trigger('focus');
                })
                .appendTo($li);
            $list.append($li);
        });
        $app.find('.llms-count').text(state.urls.length + ' / ' + MAX);
        $app.find('.llms-empty').prop('hidden', state.urls.length > 0);

        var $sugs = $app.find('.llms-sugs');
        $sugs.find('.llms-sug').remove();
        var h = host();
        var shown = 0;
        if (h && state.urls.length < MAX) {
            $.each(SUGGESTED, function (_, path) {
                var full = 'https://' + h + path;
                if (state.urls.indexOf(full) >= 0) return;
                shown++;
                $('<button type="button" class="llms-sug"/>').text('+ ' + path)
                    .on('click', function () { addUrls(full); })
                    .appendTo($sugs);
            });
        }
        $sugs.prop('hidden', shown === 0);
    }

    // ─── Pantalla final ───────────────────────────────────────────────────
    function renderDone() {
        var first = val('name').split(/\s+/)[0] || 'gracias';
        $app.find('[data-out="first"]').text(first);
        $app.find('[data-out="biz"]').text(val('biz') || 'tu negocio');
        $app.find('[data-out="count"]').text(state.urls.length);
        $app.find('[data-out="email"]').text(val('email'));

        var $body = $app.find('.llms-preview-body').empty();
        function line(text, cls) { $('<div/>').addClass(cls || '').text(text).appendTo($body); }
        line('# ' + (val('biz') || 'Tu negocio'), 'llms-pv-h');
        line('> Lo que hace tu negocio, en una frase…', 'llms-pv-dim');
        line('## Contacto', 'llms-pv-h llms-pv-gap');
        line('- Teléfono: ' + val('phone'));
        line('## Páginas', 'llms-pv-h llms-pv-gap');
        $.each(state.urls.slice(0, 6), function (_, u) { line('- ' + pretty(u), 'llms-pv-url'); });
        if (state.urls.length > 6) line('  … y ' + (state.urls.length - 6) + ' más', 'llms-pv-dim');
    }

    function showView(name) {
        $app.find('.llms-view').each(function () {
            $(this).prop('hidden', !$(this).hasClass('llms-view-' + name));
        });
    }

    // ─── Envío ────────────────────────────────────────────────────────────
    function submit() {
        if (val('url')) { addUrls(val('url')); return; }
        for (var s = 1; s <= 3; s++) {
            if (!$.isEmptyObject(errorsFor(s))) { goTo(s, false); validate(s); return; }
        }
        showAlert('');
        $next.prop('disabled', true);
        showView('sending');

        $.post(llmstxtAjax.ajax_url, {
            action:  'llmstxt_generate',
            nonce:   llmstxtAjax.nonce,
            name:    val('name'),
            email:   val('email'),
            website: 'https://' + host(),
            biz:     val('biz'),
            address: val('address'),
            city:    val('city'),
            phone:   val('phone'),
            urls:    state.urls.join('\n'),
            profiles: profiles()[0].join('\n')
        })
        .done(function (res) {
            if (res && res.success) {
                renderDone();
                showView('done');
                reveal($app.find('.llms-done-title'));
                return;
            }
            fail(res);
        })
        .fail(function (xhr) { fail(xhr.responseJSON, xhr.status); })
        .always(function () { $next.prop('disabled', false); });
    }

    function fail(res, status) {
        showView('form');
        var data = (res && res.data) || {};
        var msg = data.message || (status === 403
            ? 'La página lleva demasiado tiempo abierta. Recárgala e inténtalo de nuevo.'
            : 'No pudimos enviar tu solicitud. Revisa tu conexión e inténtalo de nuevo.');
        if (data.field) {
            var step = 1;
            $.each(STEP_FIELDS, function (s, fields) { if (fields.indexOf(data.field) >= 0) step = parseInt(s, 10); });
            goTo(step, false);
            setError(data.field, msg);
            revealField($(data.field === 'urls' ? '#llms-url' : '#llms-' + data.field));
        } else {
            showAlert(msg);
        }
    }

    // ─── Eventos ──────────────────────────────────────────────────────────
    $form.on('submit', function (e) {
        e.preventDefault();
        if (state.step < 3) {
            if (validate(state.step)) goTo(state.step + 1);
        } else {
            submit();
        }
    });

    $back.on('click', function () { goTo(state.step - 1); });

    $app.on('input', 'input, textarea', function () {
        var id = (this.id || '').replace('llms-', '');
        setError(id === 'url' ? 'urls' : id, '');
        if (id === 'domain' && state.step === 3) renderUrls();
    });

    $('#llms-url').on('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); addUrls(val('url')); }
    });
    $('#llms-url').on('paste', function () {
        var el = this;
        setTimeout(function () { if (/[\s,]/.test($.trim(el.value))) addUrls(el.value); }, 0);
    });
    $('#llms-add').on('click', function () { addUrls(val('url')); $('#llms-url').trigger('focus'); });

    $app.find('.llms-reset').on('click', function () {
        $form[0].reset();
        state.urls = [];
        $.each(['name', 'email', 'domain', 'biz', 'address', 'city', 'phone', 'profiles', 'urls'], function (_, f) { setError(f, ''); });
        showView('form');
        goTo(1);
    });

    goTo(1, false);
});
