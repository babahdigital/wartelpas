(function () {
    window.Pass = function (id) {
        var input = document.getElementById(id);
        if (!input) return;
        input.type = input.type === 'password' ? 'text' : 'password';
    };

    var shell = document.querySelector('.admin-shell');
    if (!shell) return;

    var tabs = shell.querySelectorAll('[data-tab]');
    var views = shell.querySelectorAll('[data-view]');

    function setActiveTab(tabId) {
        tabs.forEach(function (tab) {
            tab.classList.toggle('active', tab.getAttribute('data-tab') === tabId);
        });
        views.forEach(function (view) {
            view.classList.toggle('active', view.getAttribute('data-view') === tabId);
        });
    }

    function executeInlineScripts(container) {
        var scripts = container.querySelectorAll('script');
        scripts.forEach(function (script) {
            if (script.src) {
                var newScript = document.createElement('script');
                newScript.src = script.src;
                newScript.async = false;
                document.head.appendChild(newScript);
            } else {
                try {
                    Function(script.textContent)();
                } catch (e) {}
            }
        });
    }

    function showMessage(view, message) {
        view.innerHTML = '<div class="admin-empty">' + message + '</div>';
    }

    function loadSection(tabId, sessionValue) {
        var view = shell.querySelector('[data-view="' + tabId + '"]');
        if (!view) return;

        if (tabId === 'sessions' || tabId === 'operator') return;
        if (!sessionValue) {
            showMessage(view, 'Pilih sesi terlebih dahulu.');
            return;
        }

        var lastSession = view.getAttribute('data-loaded-session');
        if (lastSession === sessionValue && view.getAttribute('data-loaded') === '1') {
            return;
        }

        view.setAttribute('data-loaded-session', sessionValue);
        view.setAttribute('data-loaded', '0');
        view.innerHTML = '<div class="admin-loading">Memuat data...</div>';

        var section = tabId === 'settings' ? 'settings' : 'scripts';
        var url = 'admin.php?id=admin-content&section=' + encodeURIComponent(section) + '&session=' + encodeURIComponent(sessionValue);

        fetch(url, { credentials: 'same-origin' })
            .then(function (res) { return res.text(); })
            .then(function (html) {
                view.innerHTML = html;
                view.setAttribute('data-loaded', '1');
                executeInlineScripts(view);
            })
            .catch(function () {
                showMessage(view, 'Gagal memuat data. Silakan coba lagi.');
            });
    }

    function switchTab(tabId, sessionValue) {
        if (!tabId) return;
        var currentSession = sessionValue || shell.getAttribute('data-session') || '';
        shell.setAttribute('data-session', currentSession);
        var sessionBadge = shell.querySelector('[data-session-badge]');
        if (sessionBadge) {
            sessionBadge.textContent = 'Sesi: ' + (currentSession || '-');
        }
        setActiveTab(tabId);
        loadSection(tabId, currentSession);
    }

    function getSessionFromUrl(href) {
        try {
            var url = new URL(href, window.location.origin);
            return url.searchParams.get('session') || '';
        } catch (e) {
            return '';
        }
    }

    shell.addEventListener('click', function (event) {
        var tabButton = event.target.closest('[data-tab]');
        if (tabButton) {
            event.preventDefault();
            switchTab(tabButton.getAttribute('data-tab'));
            return;
        }

        var link = event.target.closest('a[href]');
        if (!link) return;

        if (link.getAttribute('data-no-ajax') === '1') {
            return;
        }

        var href = link.getAttribute('href');
        if (!href) return;

        if (href.indexOf('router=') !== -1) {
            return;
        }

        if (href.indexOf('admin.php?id=settings') !== -1) {
            event.preventDefault();
            var sess = getSessionFromUrl(href);
            switchTab('settings', sess);
            return;
        }

        if (href.indexOf('admin.php?id=mikrotik-scripts') !== -1) {
            event.preventDefault();
            var sessScript = getSessionFromUrl(href);
            switchTab('scripts', sessScript);
            return;
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') return;
        if (!shell.contains(form)) return;

        var activeView = shell.querySelector('.view-section.active');
        if (!activeView || !activeView.contains(form)) return;

        if (activeView.getAttribute('data-view') !== 'settings') return;
        if (!shell.getAttribute('data-session')) return;

        event.preventDefault();
        var submitBtn = form.querySelector('input[type="submit"], button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.setAttribute('data-label', submitBtn.textContent || submitBtn.value || '');
            if (submitBtn.textContent) submitBtn.textContent = 'Menyimpan...';
            if (submitBtn.value) submitBtn.value = 'Menyimpan...';
        }

        var formData = new FormData(form);
        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        }).then(function (res) {
            return res.text();
        }).then(function () {
            loadSection('settings', shell.getAttribute('data-session'));
        }).catch(function () {
            showMessage(activeView, 'Gagal menyimpan data.');
        }).finally(function () {
            if (submitBtn) {
                submitBtn.disabled = false;
                var label = submitBtn.getAttribute('data-label');
                if (submitBtn.textContent) submitBtn.textContent = label || 'Simpan';
                if (submitBtn.value) submitBtn.value = label || 'Simpan';
            }
        });
    }, true);

    function padNumber(num) {
        return num < 10 ? '0' + num : String(num);
    }

    function updateClock() {
        var clock = document.getElementById('timer_val');
        if (!clock) return;
        var now = new Date();
        clock.textContent = padNumber(now.getHours()) + ':' + padNumber(now.getMinutes()) + ':' + padNumber(now.getSeconds());
    }

    var defaultTab = shell.getAttribute('data-active-tab') || 'sessions';
    var defaultSession = shell.getAttribute('data-session') || '';
    setActiveTab(defaultTab);
    loadSection(defaultTab, defaultSession);
    updateClock();
    setInterval(updateClock, 1000);
})();
