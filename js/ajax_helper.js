(function () {
  function addCacheBuster(url) {
    if (!url) return url;
    var hasQuery = url.indexOf('?') !== -1;
    var bust = '_t=' + Date.now();
    return url + (hasQuery ? '&' : '?') + bust;
  }

  function extractRedirect(html) {
    if (!html) return '';
    var match = html.match(/window\.location(?:\.href)?\s*=\s*['"]([^'"]+)['"]/i);
    if (match && match[1]) return match[1];
    match = html.match(/<meta[^>]+http-equiv=["']refresh["'][^>]+content=["'][0-9]+;\s*url=([^"']+)["']/i);
    return match && match[1] ? match[1] : '';
  }

  function runInlineScripts(container) {
    if (!container) return;
    var scripts = Array.prototype.slice.call(container.querySelectorAll('script'));
    scripts.forEach(function (script) {
      var newScript = document.createElement('script');
      if (script.src) {
        newScript.src = script.src;
        newScript.async = false;
      } else {
        newScript.text = script.textContent || '';
      }
      document.head.appendChild(newScript);
      script.parentNode.removeChild(script);
    });
  }

  function ajaxLoad(url) {
    if (!url) return;
    var target = document.getElementById('temp');
    if (!target || !window.fetch) {
      window.location.href = url;
      return;
    }

    if (typeof window.notify === 'function') {
      window.notify('Loading...');
    }

    var requestUrl = addCacheBuster(url);
    var controller = new AbortController();
    var timeout = setTimeout(function () {
      controller.abort();
    }, 20000);

    fetch(requestUrl, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      signal: controller.signal
    })
      .then(function (resp) {
        clearTimeout(timeout);
        if (resp.redirected && resp.url) {
          window.location.href = resp.url;
          throw new Error('redirect');
        }
        var contentType = resp.headers.get('content-type') || '';
        if (contentType.indexOf('application/json') !== -1) {
          window.location.href = url;
          throw new Error('json-response');
        }
        if (!resp.ok) {
          throw new Error('HTTP ' + resp.status);
        }
        return resp.text();
      })
      .then(function (html) {
        var redirectUrl = extractRedirect(html);
        if (redirectUrl) {
          window.location.href = redirectUrl;
          return;
        }
        target.innerHTML = html;
        runInlineScripts(target);
      })
      .catch(function (err) {
        console.error('Ajax load failed', err);
        window.location.href = url;
      });
  }

  window.loadpage = ajaxLoad;
  window.dellSelected = ajaxLoad;
  window.connect = function (sessionId) {
    if (!sessionId) return;
    ajaxLoad('./admin.php?id=connect&session=' + encodeURIComponent(sessionId));
  };
  window.stheme = function (url) {
    if (!url) return;
    ajaxLoad(url);
  };
})();
