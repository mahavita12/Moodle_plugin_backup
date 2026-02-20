'use strict';
(function() {
  if (window.__localTaggedTopicsInjected) { return; }
  window.__localTaggedTopicsInjected = true;

  var BATCH_DELAY_MS = 200;
  var observer = null;
  var pending = new Set();
  var anchorMap = new Map(); // cmid -> [elements]
  var eventMap = new Map(); // eventid -> [elements]
  var DEBUG = false;
  try { DEBUG = !!(window.localStorage && window.localStorage.getItem('ttdebug')); } catch (e) {}
  function dbg() { if (DEBUG && window.console && console.log) { try { console.log.apply(console, ['[taggedtopics]'].concat([].slice.call(arguments))); } catch (e) {} } }

  function getSesskey() {
    try { return (window.M && window.M.cfg && window.M.cfg.sesskey) ? window.M.cfg.sesskey : ''; } catch (e) { return ''; }
  }

  function isCoursePage() {
    return location.pathname.indexOf('/course/view.php') !== -1;
  }

  function isQuizModulePage() {
    // Any Quiz module page (view, attempt, review, summary, etc.).
    return location.pathname.indexOf('/mod/quiz/') !== -1;
  }

  function parseCmidFromUrl(href) {
    try {
      var url = new URL(href, location.origin);
      if (url.pathname.indexOf('/mod/quiz/') === -1) return null;
      // Prefer cmid in id or cmid param.
      var id = url.searchParams.get('id');
      var cmid = url.searchParams.get('cmid');
      return id ? parseInt(id, 10) : (cmid ? parseInt(cmid, 10) : null);
    } catch (e) { return null; }
  }

  function parseEventIdFromUrl(href) {
    try {
      var url = new URL(href, location.origin);
      var evid = url.searchParams.get('id') || url.searchParams.get('eventid') || url.searchParams.get('event');
      return evid ? parseInt(evid, 10) : null;
    } catch (e) { return null; }
  }

  function collectAnchors(root) {
    // Avoid injecting on course page (already handled by course format override).
    if (isCoursePage()) return;
    // Avoid injecting on any quiz module pages (view/attempt/review/etc.).
    if (isQuizModulePage()) return;

    var anchors = [];

    // Dashboard Timeline: only inject next to the event title link, not action buttons.
    var timelineRoot = document.querySelector('[data-region="event-list-container"]');
    if (timelineRoot) {
      anchors = anchors.concat(Array.from((root || document).querySelectorAll(
        '[data-region="event-list-item"] h6.event-name a[href*="/mod/quiz/view.php"]'
      )));
    }

    // Calendar full event cards (calendar/view.php and block full cards in dashboard):
    // Find footer links to the activity and inject next to the header title h3.name.
    var calendarWrappers = (root || document).querySelectorAll('.calendarwrapper, [data-region="calendar"]');
    if (calendarWrappers.length) {
      // Full event items.
      // Prefer event-id to be robust regardless of footer links.
      var eventCards = (root || document).querySelectorAll('[data-type="event"][data-event-id]');
      eventCards.forEach(function(card) {
        var evid = parseInt(card.getAttribute('data-event-id'), 10);
        if (!evid || isNaN(evid)) return;
        var headerTitle = card.querySelector('h3.name');
        if (!headerTitle) return;
        if (!eventMap.has(evid)) eventMap.set(evid, []);
        eventMap.get(evid).push(headerTitle);
        pending.add('e' + evid);
      });

      // Fallback: if there are direct mod links in footer, use cmids too.
      var cardLinks = (root || document).querySelectorAll('[data-type="event"] .card-footer a[href*="/mod/quiz/view.php"]');
      cardLinks.forEach(function(link) {
        var cmid = parseCmidFromUrl(link.getAttribute('href'));
        if (!cmid || isNaN(cmid)) return;
        var card = link.closest('[data-type="event"]');
        if (!card) return;
        var headerTitle = card.querySelector('h3.name');
        if (!headerTitle) return;
        if (!anchorMap.has(cmid)) anchorMap.set(cmid, []);
        anchorMap.get(cmid).push(headerTitle);
        pending.add(cmid);
      });

      // Mini-calendar day popover entries: anchors inside popover content.
      // Mini-calendar day popover entries: anchors to calendar event page (parse event id).
      var miniPopoverLinks = (root || document).querySelectorAll('[data-popover-eventtype-] a');
      miniPopoverLinks.forEach(function(link) {
        var evid = parseEventIdFromUrl(link.getAttribute('href'));
        if (!evid || isNaN(evid)) return;
        if (!eventMap.has(evid)) eventMap.set(evid, []);
        eventMap.get(evid).push(link);
        pending.add('e' + evid);
      });

      // Upcoming events list items under the mini calendar.
      var upcomingAnchors = (root || document).querySelectorAll('.calendarwrapper [data-region="event-item"] h6 a[data-event-id]');
      upcomingAnchors.forEach(function(a) {
        var evid = parseInt(a.getAttribute('data-event-id'), 10);
        if (!evid || isNaN(evid)) return;
        if (!eventMap.has(evid)) eventMap.set(evid, []);
        eventMap.get(evid).push(a);
        pending.add('e' + evid);
      });

      // Month detailed day cells (links with data-event-id inside li[data-region="event-item"]).
      var monthAnchors = (root || document).querySelectorAll('.calendarwrapper [data-region="day-content"] li[data-region="event-item"] a[data-action="view-event"][data-event-id]');
      monthAnchors.forEach(function(a) {
        var evid2 = parseInt(a.getAttribute('data-event-id'), 10);
        if (!evid2 || isNaN(evid2)) return;
        if (!eventMap.has(evid2)) eventMap.set(evid2, []);
        eventMap.get(evid2).push(a);
        pending.add('e' + evid2);

        // Fallback via CMID if the href links directly to the module view.
        var href = a.getAttribute('href') || '';
        if (href.indexOf('/mod/quiz/view.php') !== -1) {
          var cmid2 = parseCmidFromUrl(href);
          if (cmid2 && !isNaN(cmid2)) {
            if (!anchorMap.has(cmid2)) anchorMap.set(cmid2, []);
            anchorMap.get(cmid2).push(a);
            pending.add(cmid2);
          }
        }
      });
    }

    if (!anchors.length && !calendarWrappers.length && eventMap.size === 0) return;

    anchors.forEach(function(a) {
      if (a.dataset.taggedtopicsInjected === '1') return;
      var cmid = parseCmidFromUrl(a.getAttribute('href'));
      if (!cmid || isNaN(cmid)) return;
      if (!anchorMap.has(cmid)) anchorMap.set(cmid, []);
      anchorMap.get(cmid).push(a);
      pending.add(cmid);
    });
    dbg('collectAnchors:', {cmids: anchors.length, eventTargets: eventMap.size});
    scheduleFlush();
  }

  var flushTimer = null;
  function scheduleFlush() {
    if (flushTimer) return;
    flushTimer = setTimeout(function() {
      flushTimer = null;
      flush();
    }, BATCH_DELAY_MS);
  }

  function flush() {
    if (pending.size === 0) return;
    var keys = Array.from(pending);
    pending.clear();

    var cmids = [];
    var eventids = [];
    keys.forEach(function(k) {
      if (typeof k === 'string' && k.charAt(0) === 'e') {
        var evid = parseInt(k.slice(1), 10);
        if (!isNaN(evid)) eventids.push(evid);
      } else {
        var v = parseInt(k, 10);
        if (!isNaN(v)) cmids.push(v);
      }
    });

    // Call AJAX service.
    var sesskey = getSesskey();
    var url = (window.M && window.M.cfg && M.cfg.wwwroot ? M.cfg.wwwroot : '') + '/lib/ajax/service.php' + (sesskey ? ('?sesskey=' + encodeURIComponent(sesskey)) : '');
    var payload = [];
    if (cmids.length) payload.push({ methodname: 'local_taggedtopics_get_tags', args: { cmids: cmids } });
    if (eventids.length) payload.push({ methodname: 'local_taggedtopics_get_tags_by_events', args: { eventids: eventids } });
    if (!payload.length) return;

    dbg('flush payload:', payload);
    fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    }).then(function(r) { return r.json(); })
      .then(function(res) {
        if (!Array.isArray(res)) return;
        dbg('flush response:', res);
        res.forEach(function(call) {
          if (!call || !call.data || !Array.isArray(call.data.items)) return;
          call.data.items.forEach(function(item) {
            var html = item.html || '';
            if (!html) return;

            if (item.cmid) {
              var cmid = item.cmid;
              if (!anchorMap.has(cmid)) return;
              var anchors = anchorMap.get(cmid);
              anchors.forEach(function(a) { injectTagNextTo(a, html); });
            } else if (item.eventid) {
              var evid = item.eventid;
              if (!eventMap.has(evid)) return;
              var nodes = eventMap.get(evid);
              nodes.forEach(function(n) { injectTagNextTo(n, html, true); });
            }
          });
        });
      }).catch(function() { /* ignore */ });
  }

  function injectTagNextTo(target, html, forceBelow) {
    if (!target) return;
    if (target.dataset && target.dataset.taggedtopicsInjected === '1') return;
    var isBlockPlacement = forceBelow || (target.tagName === 'H3') || target.closest('.calendarwrapper') || target.closest('[data-type="event"]');
    // Remove existing nearby injected nodes to prevent duplicates.
    var sibling = target.nextSibling;
    while (sibling && sibling.nodeType === 3) { sibling = sibling.nextSibling; }
    if (sibling && sibling.nodeType === 1) {
      if (sibling.classList.contains('local-taggedtopics-afterline')) {
        sibling.parentNode.removeChild(sibling);
      } else if (!isBlockPlacement && sibling.classList.contains('activity-tag')) {
        sibling.parentNode.removeChild(sibling);
      }
    }
    if (isBlockPlacement) {
      target.insertAdjacentHTML('afterend', '<div class="local-taggedtopics-afterline">' + html + '</div>');
    } else {
      target.insertAdjacentHTML('afterend', ' ' + html);
    }
    if (target.dataset) target.dataset.taggedtopicsInjected = '1';
  }

  function startObserver() {
    try {
      observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
          for (var i = 0; i < m.addedNodes.length; i++) {
            var n = m.addedNodes[i];
            if (n.nodeType !== 1) continue;
            collectAnchors(n);
          }
        });
      });
      observer.observe(document.body, { childList: true, subtree: true });
    } catch (e) { /* ignore */ }
  }

  function init() {
    if (!isCoursePage() && !isQuizModulePage()) {
      collectAnchors(document);
      startObserver();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
