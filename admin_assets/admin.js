(function () {
    window.Pass = function (id) {
        var input = document.getElementById(id);
        if (!input) return;
        input.type = input.type === 'password' ? 'text' : 'password';
    };

    window.PassMk = function () {
        var input = document.getElementById('passmk');
        if (!input) return;
        input.type = input.type === 'password' ? 'text' : 'password';
    };

    var shell = document.querySelector('.admin-shell');
    if (!shell) return;

    var tabs = shell.querySelectorAll('[data-tab]');
    var views = shell.querySelectorAll('[data-view]');
    var disableAjax = true;

    function setActiveTab(tabId) {
        tabs.forEach(function (tab) {
            tab.classList.toggle('active', tab.getAttribute('data-tab') === tabId);
        });
        views.forEach(function (view) {
            view.classList.toggle('active', view.getAttribute('data-view') === tabId);
            view.style.display = view.getAttribute('data-view') === tabId ? 'block' : 'none';
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
        if (disableAjax) return;
        var view = shell.querySelector('[data-view="' + tabId + '"]');
        if (!view) return;

        if (tabId === 'sessions') return;
        if (tabId !== 'operator' && tabId !== 'whatsapp' && !sessionValue) {
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

        var section = tabId === 'settings' ? 'settings' : (tabId === 'scripts' ? 'scripts' : (tabId === 'whatsapp' ? 'whatsapp' : 'operator'));
        var url = 'admin.php?id=admin-content&section=' + encodeURIComponent(section);
        if (sessionValue) {
            url += '&session=' + encodeURIComponent(sessionValue);
        }

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

    function buildTabUrl(tabId, sessionValue) {
        var base = 'admin.php';
        if (tabId === 'settings') {
            return base + '?id=settings' + (sessionValue ? '&session=' + encodeURIComponent(sessionValue) : '');
        }
        if (tabId === 'scripts') {
            return base + '?id=mikrotik-scripts' + (sessionValue ? '&session=' + encodeURIComponent(sessionValue) : '');
        }
        if (tabId === 'operator') {
            return base + '?id=operator-access';
        }
        if (tabId === 'whatsapp') {
            return base + '?id=whatsapp';
        }
        return base + '?id=sessions';
    }

    function switchTab(tabId, sessionValue) {
        if (!tabId) return;
        var currentSession = sessionValue || shell.getAttribute('data-session') || '';
        shell.setAttribute('data-session', currentSession);
        var sessionBadge = shell.querySelector('[data-session-badge]');
        if (sessionBadge) {
            sessionBadge.textContent = 'Sesi: ' + (currentSession || '-');
        }
        if (disableAjax) {
            var nextUrl = buildTabUrl(tabId, currentSession);
            window.location.href = nextUrl;
            return;
        }

        setActiveTab(tabId);
        loadSection(tabId, currentSession);

        var needsSession = (tabId === 'settings' || tabId === 'scripts');
        if (!needsSession || currentSession) {
            var nextUrl = buildTabUrl(tabId, currentSession);
            if (window.history && window.history.replaceState) {
                window.history.replaceState({}, document.title, nextUrl);
            }
        }
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

        if (link.hasAttribute('data-delete-session')) {
            event.preventDefault();
            var sessionName = link.getAttribute('data-delete-session') || '';
            var targetUrl = link.getAttribute('href') || '';
            if (window.MikhmonPopup) {
                window.MikhmonPopup.open({
                    title: 'Konfirmasi Hapus',
                    iconClass: 'fa fa-trash',
                    statusIcon: 'fa fa-exclamation-triangle',
                    statusColor: '#f59e0b',
                    messageHtml: '<div style="text-align:center;">Hapus sesi <strong>' + sessionName + '</strong>?</div>',
                    buttons: [
                        { label: 'Batal', className: 'm-btn m-btn-cancel' },
                        { label: 'Hapus', className: 'm-btn m-btn-danger', onClick: function () { window.location.href = targetUrl; } }
                    ],
                    sizeClass: 'is-small'
                });
            } else if (confirm('Hapus sesi ' + sessionName + '?')) {
                window.location.href = targetUrl;
            }
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
        if (form.getAttribute('data-no-ajax') === '1') return;
        if (disableAjax) return;

        var viewName = form.getAttribute('data-admin-form') || '';
        var activeView = shell.querySelector('.view-section.active');
        if (!viewName) {
            if (!activeView || !activeView.contains(form)) return;
            viewName = activeView.getAttribute('data-view') || '';
        }
        if (viewName === 'settings' && !shell.getAttribute('data-session')) return;
        if (viewName !== 'settings' && viewName !== 'whatsapp' && viewName !== 'operator') return;
        if (!activeView || !activeView.contains(form)) {
            activeView = shell.querySelector('[data-view="' + viewName + '"]') || activeView;
        }

        event.preventDefault();
        var submitBtn = form.querySelector('input[type="submit"], button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.setAttribute('data-label', submitBtn.textContent || submitBtn.value || '');
            if (submitBtn.textContent) submitBtn.textContent = 'Menyimpan...';
            if (submitBtn.value) submitBtn.value = 'Menyimpan...';
        }

        var formData = new FormData(form);
        var postUrl = viewName === 'whatsapp'
            ? 'admin.php?id=admin-content&section=whatsapp'
            : (viewName === 'settings'
                ? 'admin.php?id=admin-content&section=settings&session=' + encodeURIComponent(shell.getAttribute('data-session') || '')
                : 'admin.php?id=admin-content&section=operator');
        fetch(postUrl, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (res) {
            var contentType = (res.headers.get('content-type') || '').toLowerCase();
            if (contentType.indexOf('application/json') !== -1) {
                return res.json().then(function (json) { return { type: 'json', data: json }; });
            }
            return res.text().then(function (html) { return { type: 'html', data: html }; });
        }).then(function (payload) {
            if (viewName === 'operator') {
                if (payload.type === 'json') {
                    var ok = payload.data && payload.data.ok;
                    var message = (payload.data && payload.data.message) || (ok ? 'Data tersimpan.' : 'Gagal menyimpan data.');
                    if (typeof window.notify === 'function') {
                        window.notify(message, ok ? 'success' : 'error');
                    } else {
                        var targetView = activeView || shell.querySelector('[data-view="operator"]');
                        if (targetView) {
                            targetView.insertAdjacentHTML('afterbegin',
                                '<div class="alert ' + (ok ? 'alert-success' : 'alert-danger') + '" style="margin-bottom: 15px; padding: 15px; border-radius: 10px;">' + message + '</div>'
                            );
                        }
                    }
                    return;
                }

                var opView = activeView || shell.querySelector('[data-view="operator"]');
                if (opView && typeof payload.data === 'string') {
                    if (payload.data.trim() !== '') {
                        opView.innerHTML = payload.data;
                        opView.setAttribute('data-loaded', '1');
                        executeInlineScripts(opView);
                        var existingAlert = opView.querySelector('.alert');
                        if (!existingAlert) {
                            opView.insertAdjacentHTML('afterbegin',
                                '<div class="alert alert-success" style="margin-bottom: 15px; padding: 15px; border-radius: 10px;">Data admin & operator tersimpan.</div>'
                            );
                        }
                    } else {
                        opView.insertAdjacentHTML('afterbegin',
                            '<div class="alert alert-success" style="margin-bottom: 15px; padding: 15px; border-radius: 10px;">Data admin & operator tersimpan.</div>'
                        );
                    }
                }
                return;
            }

            var html = payload.data;
            if (viewName === 'whatsapp' || viewName === 'settings') {
                activeView.innerHTML = html;
                activeView.setAttribute('data-loaded', '1');
                executeInlineScripts(activeView);

                var alertBox = activeView.querySelector('.alert');
                if (!alertBox && viewName === 'whatsapp') {
                    var cardBody = activeView.querySelector('.card-body-modern');
                    if (cardBody) {
                        cardBody.insertAdjacentHTML('afterbegin',
                            '<div class="alert alert-success" style="margin-bottom: 15px; padding: 15px; border-radius: 10px;">Konfigurasi WhatsApp tersimpan.</div>'
                        );
                    }
                }

                if (!alertBox && viewName === 'settings') {
                    var settingsBody = activeView.querySelector('.card-body-modern');
                    if (settingsBody) {
                        settingsBody.insertAdjacentHTML('afterbegin',
                            '<div class="alert alert-success" style="margin-bottom: 15px; padding: 15px; border-radius: 10px;">Konfigurasi router tersimpan.</div>'
                        );
                    }
                }

                if (viewName === 'settings') {
                    var newSessionNode = activeView.querySelector('[data-new-session]');
                    if (newSessionNode) {
                        var newSession = newSessionNode.getAttribute('data-new-session') || '';
                        if (newSession) {
                            shell.setAttribute('data-session', newSession);
                            var badge = shell.querySelector('[data-session-badge]');
                            if (badge) badge.textContent = 'Sesi: ' + newSession;
                            if (window.history && window.history.replaceState) {
                                window.history.replaceState({}, document.title, 'admin.php?id=settings&session=' + encodeURIComponent(newSession));
                            }
                        }
                    }
                }

                if (viewName === 'whatsapp' && window.history && window.history.replaceState) {
                    window.history.replaceState({}, document.title, 'admin.php?id=whatsapp');
                }
            } else if (viewName !== 'operator') {
                loadSection('settings', shell.getAttribute('data-session'));
            }
        }).catch(function () {
            if (viewName === 'operator') {
                if (typeof window.notify === 'function') {
                    window.notify('Gagal menyimpan data.', 'error');
                } else {
                    showMessage(activeView, 'Gagal menyimpan data.');
                }
            } else {
                showMessage(activeView, 'Gagal menyimpan data.');
            }
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
    if (!disableAjax) {
        loadSection(defaultTab, defaultSession);
    }
    updateClock();
    setInterval(updateClock, 1000);
})();
