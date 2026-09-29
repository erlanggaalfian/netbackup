</div> <!-- penutup .container -->

<footer class="footer">
    <div class="footer-content">
        <div class="footer-brand-row">
            <img src="/img/logo.png" alt="" class="footer-logo" width="28" height="28">
            <span class="footer-brand-name">NetBackup</span>
            <span class="footer-version">v<?php echo htmlspecialchars(app_version()); ?></span>
        </div>
        <p class="footer-copy">&copy; <?php echo date('Y'); ?> Erlangga Alfian</p>
    </div>
</footer>

<script src="/web/js/auto-filter.js"></script>

<script>
(function () {
    const navbar = document.getElementById('main-navbar');
    const panel = document.getElementById('navbar-mobile-panel');
    const triggers = document.querySelectorAll('.navbar-toggle, .mobile-menu-trigger');
    if (!navbar || !panel || triggers.length === 0) return;

    function setMenuOpen(open) {
        navbar.classList.toggle('is-open', open);
        triggers.forEach(function (t) {
            t.setAttribute('aria-expanded', open ? 'true' : 'false');
            t.classList.toggle('is-active', open);
        });
        panel.setAttribute('aria-hidden', open ? 'false' : 'true');
        document.body.classList.toggle('navbar-menu-open', open);
    }

    triggers.forEach(function (trigger) {
        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            setMenuOpen(!navbar.classList.contains('is-open'));
        });
    });

    panel.querySelectorAll('.nav-link, .btn').forEach(function (link) {
        link.addEventListener('click', function () {
            setMenuOpen(false);
        });
    });

    // Klik di luar panel & trigger menutup menu
    document.addEventListener('click', function (e) {
        if (navbar.classList.contains('is-open')) {
            const isInsidePanel = panel.contains(e.target);
            const isInsideTrigger = Array.from(triggers).some(function (t) { return t.contains(e.target); });
            if (!isInsidePanel && !isInsideTrigger) {
                setMenuOpen(false);
            }
        }
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 768) setMenuOpen(false);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') setMenuOpen(false);
    });

    // Accordion toggle for mobile compact card lists
    document.addEventListener('click', function (e) {
        if (window.innerWidth <= 768) {
            const tr = e.target.closest('.table-scroll-wrap:not(.table-cards-mobile) .modern-table tbody tr');
            if (tr) {
                if (e.target.closest('a, button, input, select, label, .copy-btn')) {
                    return;
                }
                tr.classList.toggle('is-expanded');
            }
        }
    });
})();
</script>

<!-- ═══════════════════════════════════════════════════════════
     GLOBAL MOBILE BOTTOM-SHEET SELECT PICKER
     Berlaku di semua halaman — menggantikan native <select> yang kaku
     ═══════════════════════════════════════════════════════════ -->
<script>
(function () {
    function isMobile() {
        return window.innerWidth <= 768;
    }

    if (!isMobile()) return;

    /* ── Inject CSS ── */
    var s = document.createElement('style');
    s.textContent = [
        '#nb-picker{display:none;position:fixed;inset:0;z-index:10000}',
        '#nb-picker.open{display:block}',
        '#nb-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px);animation:nbFadeIn .22s ease}',
        '@keyframes nbFadeIn{from{opacity:0}to{opacity:1}}',
        '#nb-panel{position:absolute;bottom:0;left:0;right:0;background:#fff;border-radius:20px 20px 0 0;',
        '  box-shadow:0 -10px 40px rgba(0,0,0,.2);animation:nbSlide .28s cubic-bezier(.32,.72,0,1);',
        '  max-height:72vh;display:flex;flex-direction:column;padding-bottom:env(safe-area-inset-bottom,12px)}',
        '@keyframes nbSlide{from{transform:translateY(100%)}to{transform:translateY(0)}}',
        '#nb-header{display:flex;align-items:center;justify-content:space-between;',
        '  padding:14px 18px 10px;border-bottom:1px solid #f0f0f0;flex-shrink:0}',
        '#nb-title{font-size:12px;font-weight:800;color:#1e3a8a;letter-spacing:.06em;text-transform:uppercase}',
        '#nb-close{width:28px;height:28px;border-radius:50%;border:none;background:#f3f4f6;',
        '  color:#6b7280;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s}',
        '#nb-close:active{background:#e5e7eb}',
        '#nb-list{list-style:none;margin:0;padding:8px 12px 16px;overflow-y:auto;flex:1;-webkit-overflow-scrolling:touch}',
        '#nb-list li{display:flex;align-items:center;gap:12px;padding:13px 14px;',
        '  border-radius:10px;font-size:15px;font-weight:500;color:#374151;cursor:pointer;',
        '  transition:background .12s,color .12s;margin-bottom:2px;-webkit-tap-highlight-color:transparent}',
        '#nb-list li.nb-sel{background:#1e3a8a;color:#fff;font-weight:700}',
        '#nb-list li .nb-ck{margin-left:auto;font-size:17px;opacity:0;transition:opacity .15s}',
        '#nb-list li.nb-sel .nb-ck{opacity:1}',
    ].join('');
    document.head.appendChild(s);

    /* ── Build DOM ── */
    var sheet = document.createElement('div');
    sheet.id = 'nb-picker';
    sheet.innerHTML =
        '<div id="nb-backdrop"></div>' +
        '<div id="nb-panel">' +
        '  <div id="nb-header">' +
        '    <span id="nb-title"></span>' +
        '    <button id="nb-close" type="button" aria-label="Tutup">✕</button>' +
        '  </div>' +
        '  <ul id="nb-list"></ul>' +
        '</div>';
    document.body.appendChild(sheet);

    var activeSelect = null;
    var suppressUntil = 0;

    function restoreBodyScroll() {
        if (document.body.classList.contains('navbar-menu-open')) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    }

    function pickerLabel(sel) {
        var host = sel.closest('td, th, .field, label');
        if (host) {
            if (host.dataset && host.dataset.label) return host.dataset.label;
            var fieldLabel = host.querySelector('label');
            if (fieldLabel && fieldLabel.textContent) return fieldLabel.textContent.trim();
        }
        return sel.getAttribute('aria-label') || 'Pilih';
    }

    function open(sel) {
        if (sheet.classList.contains('open') && activeSelect === sel) return;

        activeSelect = sel;
        document.getElementById('nb-title').textContent = pickerLabel(sel);

        var list = document.getElementById('nb-list');
        list.innerHTML = '';
        Array.from(sel.options).forEach(function (opt) {
            var li = document.createElement('li');
            li.dataset.val = opt.value;
            li.innerHTML = '<span>' + opt.text + '</span><span class="nb-ck">✓</span>';
            if (opt.value === sel.value) li.classList.add('nb-sel');
            li.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                sel.value = opt.value;
                sel.dispatchEvent(new Event('change', { bubbles: true }));
                close();
            });
            list.appendChild(li);
        });

        sheet.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function close() {
        sheet.classList.remove('open');
        restoreBodyScroll();
        activeSelect = null;
        suppressUntil = Date.now() + 350;
    }

    function onBackdropClose(e) {
        e.preventDefault();
        e.stopPropagation();
        close();
    }

    document.getElementById('nb-backdrop').addEventListener('click', onBackdropClose);
    document.getElementById('nb-backdrop').addEventListener('touchend', onBackdropClose);
    document.getElementById('nb-panel').addEventListener('click', function (e) {
        e.stopPropagation();
    });
    document.getElementById('nb-close').addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        close();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sheet.classList.contains('open')) close();
    });

    /* ── Intercept semua <select> di halaman ini ── */
    function attachPicker(sel) {
        if (sel._nbAttached) return;
        sel._nbAttached = true;

        function intercept(e) {
            if (!isMobile()) return;
            if (Date.now() < suppressUntil) return;
            e.preventDefault();
            e.stopPropagation();
            if (typeof e.stopImmediatePropagation === 'function') {
                e.stopImmediatePropagation();
            }
            open(sel);
        }

        sel.addEventListener('mousedown', intercept);
        sel.addEventListener('touchstart', intercept, { passive: false });
        sel.addEventListener('focus', function (e) {
            if (!isMobile()) return;
            e.preventDefault();
            sel.blur();
        });
    }

    function attachAll() {
        document.querySelectorAll('select').forEach(attachPicker);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachAll);
    } else {
        attachAll();
    }

    var obs = new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
            m.addedNodes.forEach(function (node) {
                if (node.nodeType === 1) {
                    if (node.tagName === 'SELECT') attachPicker(node);
                    node.querySelectorAll && node.querySelectorAll('select').forEach(attachPicker);
                }
            });
        });
    });
    obs.observe(document.body, { childList: true, subtree: true });

    window.addEventListener('resize', function () {
        if (!isMobile() && sheet.classList.contains('open')) close();
    });
})();
</script>


<!-- Motion System: Scroll Reveal + Stagger Observer -->
<script>
(function() {
    // Scroll reveal observer
    var obs = new IntersectionObserver(function(entries) {
        entries.forEach(function(e) {
            if (e.isIntersecting) {
                e.target.classList.add('visible');
                obs.unobserve(e.target);
            }
        });
    }, { threshold: 0.1 });
    document.querySelectorAll('.scroll-reveal').forEach(function(el) { obs.observe(el); });

    // Metric cards stagger
    document.querySelectorAll('.metric-card').forEach(function(card, i) {
        card.style.animationDelay = (i * 80) + 'ms';
    });

    // Alert auto-dismiss after 5 seconds
    document.querySelectorAll('.alert.success, .alert.info, .alert-success-soft').forEach(function(alert) {
        setTimeout(function() {
            alert.classList.add('dismissing');
            setTimeout(function() { alert.remove(); }, 300);
        }, 5000);
    });

    // Table row stagger on page load
    document.querySelectorAll('.modern-table tbody tr').forEach(function(row, i) {
        row.style.animationDelay = Math.min(i * 30, 300) + 'ms';
        row.style.animation = 'fade-in-up var(--dur-short) var(--ease-out) both';
    });
})();

/* Toast notification system */
window.showToast = function(message, type, duration) {
    type = type || 'info';
    duration = duration || 4000;
    var container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    var toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(function() {
        toast.classList.add('dismissing');
        setTimeout(function() { toast.remove(); }, 300);
    }, duration);
};

/* Loading state helpers */
window.setLoading = function(btn, loading) {
    if (!btn) return;
    if (loading) {
        btn.dataset.origText = btn.textContent;
        btn.classList.add('loading');
        btn.disabled = true;
    } else {
        btn.classList.remove('loading');
        btn.disabled = false;
        if (btn.dataset.origText) btn.textContent = btn.dataset.origText;
    }
};

window.showSkeleton = function(container) {
    if (!container) return;
    container.dataset.origHtml = container.innerHTML;
    var html = '';
    for (var i = 0; i < 5; i++) {
        html += '<div class="skeleton skeleton-table-row"></div>';
    }
    container.innerHTML = html;
};

window.hideSkeleton = function(container) {
    if (!container || !container.dataset.origHtml) return;
    container.innerHTML = container.dataset.origHtml;
};
</script>
</body>
</html>

