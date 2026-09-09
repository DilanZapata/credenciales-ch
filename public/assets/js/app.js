/* =====================================================================
   Sistema Corporativo de Gestion de Credenciales - cliente
   Sin dependencias externas. Compatible con la CSP estricta:
   no hay codigo en linea ni manejadores onclick en el HTML.
   ===================================================================== */
(function () {
  'use strict';

  var CFG = window.SCGCA || {};
  var BASE = CFG.basePath || '';
  var CSRF = CFG.csrf || '';

  // ---------------------------------------------------------------
  //  Utilidades
  // ---------------------------------------------------------------
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

  function api(path, options) {
    options = options || {};
    var headers = { 'Accept': 'application/json', 'X-CSRF-Token': CSRF };
    if (options.body !== undefined) { headers['Content-Type'] = 'application/json'; }
    return fetch(BASE + path, {
      method: options.method || 'GET',
      headers: headers,
      credentials: 'same-origin',
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) {
          var err = new Error(data.error || 'Error en la solicitud.');
          err.status = res.status;
          err.data = data;
          throw err;
        }
        return data;
      });
    });
  }

  function toast(message, kind) {
    var box = $('#toasts');
    if (!box) { return; }
    var el = document.createElement('div');
    el.className = 'alert alert--' + (kind || 'info');
    el.setAttribute('role', 'status');
    el.textContent = message;
    box.appendChild(el);
    setTimeout(function () { el.remove(); }, 6000);
  }

  // ---------------------------------------------------------------
  //  Reautenticacion (step-up) antes de operaciones sensibles
  // ---------------------------------------------------------------
  var reauthModal = null;

  function requireReauth(message) {
    return new Promise(function (resolve, reject) {
      var modal = $('#reauth-modal');
      if (!modal) { reject(new Error('No disponible')); return; }
      var msgEl = $('#reauth-message', modal);
      if (msgEl && message) { msgEl.textContent = message; }
      var passInput = $('#reauth-password', modal);
      var codeInput = $('#reauth-code', modal);
      var errEl = $('#reauth-error', modal);
      errEl.textContent = '';
      passInput.value = '';
      if (codeInput) { codeInput.value = ''; }
      modal.hidden = false;
      passInput.focus();

      function cleanup() {
        modal.hidden = true;
        passInput.value = '';
        if (codeInput) { codeInput.value = ''; }
        $('#reauth-confirm', modal).removeEventListener('click', onConfirm);
        $('#reauth-cancel', modal).removeEventListener('click', onCancel);
      }
      function onConfirm() {
        var btn = $('#reauth-confirm', modal);
        btn.classList.add('is-busy');
        api('/api/v1/reauth', {
          method: 'POST',
          body: { password: passInput.value, code: codeInput ? codeInput.value : '' }
        }).then(function () {
          btn.classList.remove('is-busy');
          cleanup();
          resolve(true);
        }).catch(function (err) {
          btn.classList.remove('is-busy');
          errEl.textContent = err.message || 'No fue posible confirmar su identidad.';
          passInput.select();
        });
      }
      function onCancel() { cleanup(); reject(new Error('cancelado')); }

      $('#reauth-confirm', modal).addEventListener('click', onConfirm);
      $('#reauth-cancel', modal).addEventListener('click', onCancel);
      reauthModal = { cleanup: cleanup };
    });
  }

  /** Ejecuta una llamada y, si el backend exige step-up, lo resuelve y reintenta. */
  function withReauth(fn, message) {
    return fn().catch(function (err) {
      if (err.status === 423 || (err.data && err.data.reauth_required)) {
        return requireReauth(message).then(fn);
      }
      throw err;
    });
  }

  // ---------------------------------------------------------------
  //  Revelado y copia de secretos
  // ---------------------------------------------------------------
  var timers = {};

  function hideSecret(box) {
    var value = $('.secret__value', box);
    var timer = $('.secret__timer', box);
    value.textContent = '••••••••••••';
    value.classList.add('is-hidden');
    if (timer) { timer.textContent = ''; }
    var toggle = $('[data-secret-toggle]', box);
    if (toggle) { toggle.textContent = 'Mostrar'; }
    box.dataset.revealed = '0';
    if (timers[box.dataset.credential]) {
      clearInterval(timers[box.dataset.credential]);
      delete timers[box.dataset.credential];
    }
  }

  function showSecret(box, secret, ttl) {
    var value = $('.secret__value', box);
    var timer = $('.secret__timer', box);
    value.textContent = secret;
    value.classList.remove('is-hidden');
    box.dataset.revealed = '1';
    var toggle = $('[data-secret-toggle]', box);
    if (toggle) { toggle.textContent = 'Ocultar'; }

    var left = ttl || 30;
    if (timer) { timer.textContent = 'se oculta en ' + left + 's'; }
    var key = box.dataset.credential;
    if (timers[key]) { clearInterval(timers[key]); }
    timers[key] = setInterval(function () {
      left -= 1;
      if (left <= 0) { hideSecret(box); return; }
      if (timer) { timer.textContent = 'se oculta en ' + left + 's'; }
    }, 1000);
  }

  function fetchSecret(box, copy) {
    var id = box.dataset.credential;
    var field = box.dataset.field || 'password';
    var version = box.dataset.version || '';
    var path = version
      ? '/api/v1/credenciales/' + id + '/secreto/historial/' + version
      : '/api/v1/credenciales/' + id + '/secreto';
    return withReauth(function () {
      return api(path, { method: 'POST', body: { field: field, copy: !!copy } });
    }, 'Confirme su contrasena para revelar este secreto.');
  }

  function copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); resolve(); }
      catch (e) { reject(e); }
      finally { ta.remove(); }
    });
  }

  document.addEventListener('click', function (event) {
    var toggle = event.target.closest('[data-secret-toggle]');
    if (toggle) {
      event.preventDefault();
      var box = toggle.closest('[data-credential]');
      if (box.dataset.revealed === '1') { hideSecret(box); return; }
      toggle.classList.add('is-busy');
      fetchSecret(box, false).then(function (data) {
        toggle.classList.remove('is-busy');
        showSecret(box, data.secret, data.ttl);
      }).catch(function (err) {
        toggle.classList.remove('is-busy');
        if (err.message !== 'cancelado') { toast(err.message || 'No fue posible mostrar la contrasena.', 'error'); }
      });
      return;
    }

    var copyBtn = event.target.closest('[data-secret-copy]');
    if (copyBtn) {
      event.preventDefault();
      var cbox = copyBtn.closest('[data-credential]');
      copyBtn.classList.add('is-busy');
      fetchSecret(cbox, true).then(function (data) {
        return copyToClipboard(data.secret).then(function () {
          copyBtn.classList.remove('is-busy');
          toast('Contrasena copiada. El acceso quedo registrado en la auditoria.', 'ok');
          // El portapapeles se limpia pasados 45 segundos.
          setTimeout(function () {
            copyToClipboard(' ').catch(function () {});
          }, 45000);
        });
      }).catch(function (err) {
        copyBtn.classList.remove('is-busy');
        if (err.message !== 'cancelado') { toast(err.message || 'No fue posible copiar la contrasena.', 'error'); }
      });
      return;
    }

    // Informacion de recuperacion
    var recBtn = event.target.closest('[data-recovery]');
    if (recBtn) {
      event.preventDefault();
      var rid = recBtn.dataset.recovery;
      withReauth(function () { return api('/api/v1/credenciales/' + rid + '/recuperacion'); },
        'Confirme su contrasena para ver la informacion de recuperacion.')
        .then(function (data) {
          var target = $('#recovery-info');
          if (!target) { return; }
          target.hidden = false;
          target.innerHTML = '';
          var dl = document.createElement('dl');
          dl.className = 'dl';
          [['Correo de recuperacion', data.recovery_email],
           ['Telefono de recuperacion', data.recovery_phone],
           ['Usuario de recuperacion', data.recovery_username],
           ['Informacion adicional', data.recovery_notes]].forEach(function (pair) {
            var dt = document.createElement('dt'); dt.textContent = pair[0];
            var dd = document.createElement('dd'); dd.textContent = pair[1] || '—';
            dl.appendChild(dt); dl.appendChild(dd);
          });
          target.appendChild(dl);
          recBtn.remove();
        })
        .catch(function (err) {
          if (err.message !== 'cancelado') { toast(err.message || 'No autorizado.', 'error'); }
        });
      return;
    }

    // Apertura y cierre de modales declarativos
    var opener = event.target.closest('[data-modal-open]');
    if (opener) {
      event.preventDefault();
      var m = document.getElementById(opener.dataset.modalOpen);
      if (m) {
        m.hidden = false;
        var first = m.querySelector('input,select,textarea');
        if (first) { first.focus(); }
      }
      return;
    }
    var closer = event.target.closest('[data-modal-close]');
    if (closer) {
      event.preventDefault();
      var mc = closer.closest('.modal-backdrop');
      if (mc) { mc.hidden = true; }
      return;
    }

    // Menu lateral en movil
    if (event.target.closest('[data-menu-toggle]')) {
      event.preventDefault();
      var sb = $('.sidebar');
      if (sb) { sb.classList.toggle('is-open'); }
      return;
    }

    // Pestanas locales
    var tab = event.target.closest('[data-tab]');
    if (tab) {
      event.preventDefault();
      var group = tab.closest('[data-tabs]');
      $$('[data-tab]', group).forEach(function (t) { t.classList.remove('is-active'); });
      tab.classList.add('is-active');
      $$('[data-tab-panel]', group.parentNode).forEach(function (panel) {
        panel.hidden = panel.dataset.tabPanel !== tab.dataset.tab;
      });
    }
  });

  // Confirmacion antes de acciones destructivas
  document.addEventListener('submit', function (event) {
    var form = event.target;
    var message = form.dataset.confirm;
    if (message && !window.confirm(message)) {
      event.preventDefault();
      return;
    }
    var submit = form.querySelector('[type=submit]');
    if (submit && !form.dataset.noBusy) { submit.classList.add('is-busy'); }
  });

  // ---------------------------------------------------------------
  //  Generador de contrasenas
  // ---------------------------------------------------------------
  function initGenerator() {
    var panel = $('[data-generator]');
    if (!panel) { return; }
    var target = document.getElementById(panel.dataset.generator);

    function options() {
      return {
        length: parseInt($('[name=gen_length]', panel).value, 10) || 20,
        upper: $('[name=gen_upper]', panel).checked,
        lower: $('[name=gen_lower]', panel).checked,
        digits: $('[name=gen_digits]', panel).checked,
        symbols: $('[name=gen_symbols]', panel).checked,
        exclude_ambiguous: $('[name=gen_ambiguous]', panel).checked
      };
    }

    var lengthInput = $('[name=gen_length]', panel);
    var lengthOut = $('[data-generator-length]', panel);
    if (lengthInput && lengthOut) {
      lengthInput.addEventListener('input', function () { lengthOut.textContent = lengthInput.value; });
    }

    $('[data-generate]', panel).addEventListener('click', function (e) {
      e.preventDefault();
      api('/api/v1/generador', { method: 'POST', body: options() }).then(function (data) {
        if (target) {
          target.value = data.password;
          target.type = 'text';
          target.dispatchEvent(new Event('input', { bubbles: true }));
        }
        var out = $('[data-generator-output]', panel);
        if (out) { out.textContent = data.label + ' (' + data.strength + '/100)'; }
      }).catch(function (err) { toast(err.message, 'error'); });
    });
  }

  // Medidor de robustez en vivo (la contrasena no sale del navegador
  // salvo para esta evaluacion, que no se almacena en ningun sitio).
  function initStrength() {
    $$('[data-strength-for]').forEach(function (meter) {
      var input = document.getElementById(meter.dataset.strengthFor);
      if (!input) { return; }
      var bar = $('span', meter);
      var label = $('[data-strength-label]', meter.parentNode) || null;
      var timeout = null;
      input.addEventListener('input', function () {
        clearTimeout(timeout);
        var value = input.value;
        if (!value) { bar.style.width = '0%'; if (label) { label.textContent = ''; } return; }
        timeout = setTimeout(function () {
          api('/api/v1/fortaleza', { method: 'POST', body: { password: value } }).then(function (data) {
            bar.style.width = data.strength + '%';
            meter.className = 'bar ' + (data.strength >= 70 ? 'ok' : (data.strength >= 40 ? 'warn' : 'danger'));
            if (label) { label.textContent = data.label; }
          }).catch(function () {});
        }, 320);
      });
    });
  }

  // Mostrar/ocultar campos de contrasena en formularios
  document.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-toggle-field]');
    if (!btn) { return; }
    event.preventDefault();
    var input = document.getElementById(btn.dataset.toggleField);
    if (!input) { return; }
    input.type = input.type === 'password' ? 'text' : 'password';
    btn.textContent = input.type === 'password' ? 'Mostrar' : 'Ocultar';
  });

  // ---------------------------------------------------------------
  //  Busqueda global
  // ---------------------------------------------------------------
  function initSearch() {
    var input = $('#global-search');
    if (!input) { return; }
    var results = $('#global-search-results');
    var timeout = null;

    input.addEventListener('input', function () {
      clearTimeout(timeout);
      var term = input.value.trim();
      if (term.length < 2) { results.hidden = true; results.innerHTML = ''; return; }
      timeout = setTimeout(function () {
        api('/api/v1/buscar?q=' + encodeURIComponent(term)).then(function (data) {
          results.innerHTML = '';
          var total = 0;
          (data.credentials || []).forEach(function (item) {
            total++;
            var a = document.createElement('a');
            a.href = BASE + '/credenciales/' + item.id;
            a.textContent = item.name;
            var small = document.createElement('small');
            small.textContent = (item.system || '') + (item.username ? ' · ' + item.username : '');
            a.appendChild(small);
            results.appendChild(a);
          });
          (data.systems || []).forEach(function (item) {
            total++;
            var a = document.createElement('a');
            a.href = BASE + '/sistemas/' + item.id;
            a.textContent = item.name;
            var small = document.createElement('small');
            small.textContent = 'Sistema';
            a.appendChild(small);
            results.appendChild(a);
          });
          if (total === 0) {
            var none = document.createElement('a');
            none.textContent = 'Sin resultados';
            none.href = '#';
            results.appendChild(none);
          }
          results.hidden = false;
        }).catch(function () { results.hidden = true; });
      }, 250);
    });

    document.addEventListener('click', function (e) {
      if (!input.contains(e.target) && !results.contains(e.target)) { results.hidden = true; }
    });
  }

  // ---------------------------------------------------------------
  //  Aviso de expiracion de sesion
  // ---------------------------------------------------------------
  function initSessionWatch() {
    if (!CFG.authenticated) { return; }
    var warned = false;
    setInterval(function () {
      api('/api/v1/sesion').then(function (data) {
        if (!data.authenticated) { window.location.href = BASE + '/entrar'; return; }
        if (data.expires_in_seconds < 120 && !warned) {
          warned = true;
          toast('Su sesion se cerrara por inactividad en menos de 2 minutos.', 'warn');
        }
        if (data.expires_in_seconds > 180) { warned = false; }
      }).catch(function (err) {
        if (err.status === 401) { window.location.href = BASE + '/entrar'; }
      });
    }, 60000);
  }

  // ---------------------------------------------------------------
  //  Seleccion multiple para reportes
  // ---------------------------------------------------------------
  function initSelection() {
    var all = $('[data-select-all]');
    if (all) {
      all.addEventListener('change', function () {
        $$('[data-select-item]').forEach(function (cb) { cb.checked = all.checked; });
        updateCount();
      });
    }
    document.addEventListener('change', function (e) {
      if (e.target.matches('[data-select-item]')) { updateCount(); }
    });
    function updateCount() {
      var count = $$('[data-select-item]:checked').length;
      $$('[data-selection-count]').forEach(function (el) { el.textContent = String(count); });
    }
    updateCount();
  }

  // Advertencia reforzada al exportar con contrasenas
  function initExportGuard() {
    var checkbox = $('#include_secrets');
    var warning = $('#export-warning');
    var confirmBox = $('#export-confirm');
    if (!checkbox) { return; }
    function sync() {
      if (warning) { warning.hidden = !checkbox.checked; }
      if (confirmBox) { confirmBox.required = checkbox.checked; }
    }
    checkbox.addEventListener('change', sync);
    sync();

    var form = checkbox.form;
    if (form) {
      form.addEventListener('submit', function (e) {
        if (checkbox.checked && confirmBox && !confirmBox.checked) {
          e.preventDefault();
          toast('Debe confirmar la advertencia para generar un archivo con contrasenas.', 'warn');
        }
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    initGenerator();
    initStrength();
    initSearch();
    initSessionWatch();
    initSelection();
    initExportGuard();

    // Cierra secretos visibles si la pestana pierde el foco.
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        $$('[data-credential]').forEach(function (box) {
          if (box.dataset.revealed === '1') { hideSecret(box); }
        });
      }
    });
  });
})();
