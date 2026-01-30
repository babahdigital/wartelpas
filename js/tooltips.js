(function() {
  var tooltipEl = null;
  var offsetX = 14;
  var offsetY = 18;

  function ensureTooltip() {
    if (tooltipEl) return tooltipEl;
    tooltipEl = document.createElement('div');
    tooltipEl.className = 'tooltip-bubble';
    document.body.appendChild(tooltipEl);
    return tooltipEl;
  }

  function setPosition(x, y) {
    if (!tooltipEl) return;
    var maxX = window.innerWidth - tooltipEl.offsetWidth - 8;
    var maxY = window.innerHeight - tooltipEl.offsetHeight - 8;
    var posX = Math.min(x + offsetX, maxX);
    var posY = Math.min(y + offsetY, maxY);
    tooltipEl.style.left = posX + 'px';
    tooltipEl.style.top = posY + 'px';
  }

  function getTargetWithTitle(target) {
    if (!target) return null;
    if (target.getAttribute && target.getAttribute('title')) return target;
    if (target.closest) return target.closest('[title]');
    return null;
  }

  document.addEventListener('mouseover', function(e) {
    var target = getTargetWithTitle(e.target);
    if (!target) return;
    var title = target.getAttribute('title');
    if (!title) return;
    target.setAttribute('data-tooltip-title', title);
    target.removeAttribute('title');
    var el = ensureTooltip();
    el.textContent = title;
    el.classList.add('is-visible');
    setPosition(e.clientX, e.clientY);
  }, true);

  document.addEventListener('mousemove', function(e) {
    if (!tooltipEl || !tooltipEl.classList.contains('is-visible')) return;
    setPosition(e.clientX, e.clientY);
  });

  document.addEventListener('mouseout', function(e) {
    var target = e.target;
    if (!target || !target.getAttribute) return;
    var dataTitle = target.getAttribute('data-tooltip-title');
    if (dataTitle !== null) {
      target.setAttribute('title', dataTitle);
      target.removeAttribute('data-tooltip-title');
    }
    if (tooltipEl) tooltipEl.classList.remove('is-visible');
  }, true);
})();
