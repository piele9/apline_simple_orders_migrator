/*
 * APLINE Simple Orders Migrator module for PrestaShop 9.
 * Back-office JavaScript. Loaded only on this module's own screens.
 * Drives the AJAX parse / import / e-mail flows and the product search box.
 * All user-facing texts are Polish (source language of the module).
 *
 * @author    Arkadiusz Pielechowski
 * @copyright Arkadiusz Pielechowski
 * @license   MIT - see LICENSE.md
 */
var ASOM = window.ASOM || {};

(function (ns) {
    'use strict';

    /**
     * Update a progress bar element (0-100) with an optional label.
     */
    /** Polskie nazwy elementów w licznikach importu (klucze z serwera). */
    ns.entityLabels = {
        customer: 'klienci',
        address: 'adresy',
        order: 'zamówienia',
        order_detail: 'pozycje zamówień',
        order_history: 'historia zamówień',
        message: 'wiadomości do zamówień'
    };

    /** Polskie nazwy faz importu (identyfikatory z serwera). */
    ns.phaseLabels = {
        import_customers: 'Import klientów',
        import_addresses: 'Import adresów',
        import_orders: 'Import zamówień',
        import_order_details: 'Import pozycji zamówień',
        import_order_history: 'Import historii zamówień',
        import_messages: 'Import wiadomości do zamówień',
        completed: 'Zakończono'
    };

    ns.setProgress = function (barEl, percent, label) {
        if (!barEl) {
            return;
        }
        var p = Math.max(0, Math.min(100, parseInt(percent, 10) || 0));
        barEl.style.width = p + '%';
        barEl.textContent = (typeof label === 'string' && label.length) ? label : (p + '%');
    };

    ns.ready = function (fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    };

    /**
     * Drive the resumable dump parser: POST to the AJAX endpoint repeatedly,
     * updating the progress bar, until the server reports {done:true}.
     */
    ns.initParse = function () {
        var box = document.getElementById('asom-parse');
        if (!box) {
            return;
        }
        var url = box.getAttribute('data-url');
        var bar = box.querySelector('.asom-progress-bar');
        var phase = box.querySelector('.asom-phase');
        var btn = document.getElementById('asom-parse-start');

        function fail(msg) {
            if (phase) {
                phase.textContent = msg;
            }
            if (btn) {
                btn.disabled = false;
            }
        }

        function step() {
            fetch(url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.success) {
                        fail('Błąd: ' + ((d && d.error) ? d.error : 'nieznany'));
                        return;
                    }
                    ns.setProgress(bar, d.percent, d.percent + '%');
                    if (phase) {
                        phase.textContent = d.message || '';
                    }
                    if (d.done) {
                        if (phase) {
                            phase.textContent = 'Analiza zakończona. Odświeżanie strony...';
                        }
                        setTimeout(function () { window.location.reload(); }, 800);
                    } else {
                        step();
                    }
                })
                .catch(function (e) { fail('Błąd sieci: ' + e); });
        }

        if (btn) {
            btn.addEventListener('click', function () {
                btn.disabled = true;
                box.style.display = 'block';
                step();
            });
        }
        if (box.getAttribute('data-autostart') === '1') {
            box.style.display = 'block';
            step();
        }
    };

    /**
     * Product mapping: wire each manual-link search box to the AJAX product
     * search, render a small results dropdown, and store the chosen id in the
     * paired hidden input. Choosing a result also selects the "link" radio.
     */
    ns.initProductMapping = function () {
        var root = document.getElementById('asom-pmap');
        if (!root) {
            return;
        }
        var searchUrl = root.getAttribute('data-search-url');
        var boxes = root.querySelectorAll('.asom-psearch');

        Array.prototype.forEach.call(boxes, function (box) {
            var wrap = box.closest('label');
            var hidden = wrap ? wrap.querySelector('.asom-pnew') : null;
            var results = wrap ? wrap.querySelector('.asom-presults') : null;
            var linkRadio = wrap ? wrap.querySelector('input[type=radio][value=link]') : null;
            var timer = null;

            function clearResults() {
                if (results) {
                    results.innerHTML = '';
                }
            }

            function choose(id, label) {
                if (hidden) {
                    hidden.value = id;
                }
                box.value = label;
                if (linkRadio) {
                    linkRadio.checked = true;
                }
                clearResults();
            }

            function query() {
                var q = box.value.trim();
                if (q.length < 2) {
                    clearResults();
                    return;
                }
                fetch(searchUrl + '&q=' + encodeURIComponent(q), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        clearResults();
                        if (!d || !d.success || !d.results || !results) {
                            return;
                        }
                        var menu = document.createElement('div');
                        menu.className = 'asom-presults-menu';
                        menu.style.cssText = 'position:absolute;z-index:1000;background:#fff;border:1px solid #ccc;max-height:220px;overflow:auto;min-width:240px;box-shadow:0 2px 6px rgba(0,0,0,.15);';
                        d.results.forEach(function (item) {
                            var a = document.createElement('a');
                            a.href = '#';
                            a.style.cssText = 'display:block;padding:4px 8px;color:#333;text-decoration:none;';
                            a.textContent = '#' + item.id + ' ' + item.label;
                            a.addEventListener('click', function (e) {
                                e.preventDefault();
                                choose(item.id, item.label);
                            });
                            a.addEventListener('mouseover', function () { a.style.background = '#f0f0f0'; });
                            a.addEventListener('mouseout', function () { a.style.background = '#fff'; });
                            menu.appendChild(a);
                        });
                        if (!d.results.length) {
                            var em = document.createElement('div');
                            em.style.cssText = 'padding:4px 8px;color:#999;';
                            em.textContent = 'Nie znaleziono produktów';
                            menu.appendChild(em);
                        }
                        results.appendChild(menu);
                    })
                    .catch(function () { clearResults(); });
            }

            box.addEventListener('input', function () {
                if (timer) {
                    clearTimeout(timer);
                }
                timer = setTimeout(query, 250);
            });
            box.addEventListener('focus', function () {
                if (linkRadio) {
                    linkRadio.checked = true;
                }
            });
        });

        // Hide result menus when clicking elsewhere.
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.asom-psearch') && !e.target.closest('.asom-presults')) {
                Array.prototype.forEach.call(root.querySelectorAll('.asom-presults'), function (r) {
                    r.innerHTML = '';
                });
            }
        });
    };

    /**
     * Drive the batched import: POST repeatedly, update the bar and the running
     * counts, until {done:true}, then reload to show the completion report.
     */
    ns.initImport = function () {
        var box = document.getElementById('asom-import');
        if (!box) {
            return;
        }
        var url = box.getAttribute('data-url');
        var bar = box.querySelector('.asom-progress-bar');
        var phase = box.querySelector('.asom-phase');
        var countsEl = box.querySelector('.asom-import-counts');

        function renderCounts(d) {
            if (!countsEl) {
                return;
            }
            function fmt(label, obj) {
                if (!obj) {
                    return '';
                }
                var parts = [];
                Object.keys(obj).forEach(function (k) {
                    parts.push((ns.entityLabels[k] || k) + ': ' + obj[k]);
                });
                return parts.length ? ('<div><strong>' + label + ':</strong> ' + parts.join(', ') + '</div>') : '';
            }
            countsEl.innerHTML = fmt('Zaimportowano', d.imported) + fmt('Pominięto', d.skipped) + fmt('Błędy', d.failed);
        }

        function step() {
            fetch(url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.success) {
                        if (phase) {
                            phase.textContent = 'Błąd: ' + ((d && d.error) ? d.error : 'nieznany');
                        }
                        return;
                    }
                    ns.setProgress(bar, d.percent, d.percent + '%');
                    if (phase) {
                        phase.textContent = (ns.phaseLabels[d.phase] || d.phase || '') + ' — ' + (d.message || '');
                    }
                    renderCounts(d);
                    if (d.done) {
                        if (phase) {
                            phase.textContent = 'Import zakończony. Odświeżanie strony...';
                        }
                        setTimeout(function () { window.location.reload(); }, 1000);
                    } else {
                        step();
                    }
                })
                .catch(function (e) {
                    if (phase) {
                        phase.textContent = 'Błąd sieci: ' + e;
                    }
                });
        }

        if (box.getAttribute('data-autostart') === '1') {
            step();
        }
    };

    /**
     * Drive the throttled password-reset email sender.
     */
    ns.initEmails = function () {
        var box = document.getElementById('asom-emails');
        if (!box) {
            return;
        }
        var url = box.getAttribute('data-url');
        var bar = box.querySelector('.asom-progress-bar');
        var progress = box.querySelector('.asom-progress');
        var phase = box.querySelector('.asom-phase');
        var btn = document.getElementById('asom-emails-start');

        function step() {
            fetch(url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.success) {
                        if (phase) {
                            phase.textContent = 'Błąd: ' + ((d && d.error) ? d.error : 'nieznany');
                        }
                        if (btn) {
                            btn.disabled = false;
                        }
                        return;
                    }
                    ns.setProgress(bar, d.percent, d.percent + '%');
                    if (phase) {
                        phase.textContent = d.message || '';
                    }
                    if (d.done) {
                        if (phase) {
                            phase.textContent = 'Wysłano e-maili: ' + d.sent + ' z ' + d.total + '. Odświeżanie strony...';
                        }
                        setTimeout(function () { window.location.reload(); }, 1000);
                    } else {
                        step();
                    }
                })
                .catch(function (e) {
                    if (phase) {
                        phase.textContent = 'Błąd sieci: ' + e;
                    }
                    if (btn) {
                        btn.disabled = false;
                    }
                });
        }

        if (btn) {
            btn.addEventListener('click', function () {
                btn.disabled = true;
                if (progress) {
                    progress.style.display = 'block';
                }
                step();
            });
        }
    };

    ns.ready(function () {
        ns.initParse();
        ns.initProductMapping();
        ns.initImport();
        ns.initEmails();
    });
}(ASOM));

window.ASOM = ASOM;
