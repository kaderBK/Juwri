// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*!
 * ZERO-TOUCH Track A — storefront agent chat + voice widget.
 * Boots from window.__agentChatCfg (set by the nonce'd inline bootstrap in
 * store_templates/partials/agent-chat.blade.php). Vanilla JS, no dependencies.
 *
 * Voice input degrades in layers, never breaking text chat:
 *   1. Web Speech API (SpeechRecognition) — free, instant, store locale.
 *   2. MediaRecorder → POST cfg.stt (Track B endpoint; only when present).
 *   3. Neither available / permission denied → mic hidden, typing unaffected.
 */
(function () {
    'use strict';

    var cfg = window.__agentChatCfg;
    if (!cfg || !cfg.endpoint) return;

    var root = document.getElementById('agent-chat');
    var btn = document.getElementById('agent-chat-btn');
    var panel = document.getElementById('agent-chat-panel');
    var closeBtn = document.getElementById('agent-chat-close');
    var log = document.getElementById('agent-chat-log');
    var typing = document.getElementById('agent-chat-typing');
    var hint = document.getElementById('agent-chat-hint');
    var form = document.getElementById('agent-chat-form');
    var input = document.getElementById('agent-chat-input');
    var mic = document.getElementById('agent-chat-mic');
    if (!root || !btn || !panel || !log || !form || !input) return;

    var S = cfg.strings || {};
    var sessionStoreKey = 'agentChatSession:' + cfg.storeId;
    var micDeniedKey = 'agentChatMicDenied:' + cfg.storeId;
    var sessionKey = null;
    var greeted = false;
    var sending = false;
    var hintTimer = null;

    try { sessionKey = sessionStorage.getItem(sessionStoreKey) || null; } catch (e) { /* private mode */ }

    // ---- basic UI --------------------------------------------------------

    root.hidden = false; // JS is running → reveal the launcher.

    function togglePanel(open) {
        var show = typeof open === 'boolean' ? open : panel.hidden;
        panel.hidden = !show;
        btn.setAttribute('aria-expanded', show ? 'true' : 'false');
        if (show) {
            if (!greeted) { greeted = true; addMsg('bot', S.greeting || ''); }
            input.focus();
        }
    }
    btn.addEventListener('click', function () { togglePanel(); });
    if (closeBtn) closeBtn.addEventListener('click', function () { togglePanel(false); });

    function addMsg(who, text) {
        if (!text) return null;
        var d = document.createElement('div');
        d.className = 'agent-chat__msg agent-chat__msg--' + (who === 'user' ? 'user' : 'bot');
        d.textContent = text;
        log.insertBefore(d, typing);
        log.scrollTop = log.scrollHeight;
        return d;
    }

    /* Order-confirmed card: server-sourced payload (order_id / continue_url /
       nullable eta_range — the Track C seam) rendered as DOM, never as HTML. */
    function addOrderCard(order) {
        if (!order || !order.order_id) return;
        var card = document.createElement('div');
        card.className = 'agent-chat__card';
        var title = document.createElement('b');
        title.textContent = (S.orderPlaced || 'Order placed!') + ' #' + order.order_id;
        card.appendChild(title);
        if (order.total !== null && order.total !== undefined) {
            var total = document.createElement('span');
            total.textContent = (S.total || 'Total') + ': ' + order.total;
            card.appendChild(total);
        }
        if (order.eta_range) {
            var eta = document.createElement('div');
            eta.textContent = (S.eta || 'Estimated ready in :range').replace(':range', order.eta_range);
            card.appendChild(eta);
        }
        if (order.track_url || order.continue_url) {
            var link = document.createElement('a');
            // track_url is the human tracking page; continue_url is the API JSON
            // kept for older payloads/external consumers.
            link.href = order.track_url || order.continue_url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = S.trackOrder || 'Track your order';
            card.appendChild(link);
        }
        log.insertBefore(card, typing);
        log.scrollTop = log.scrollHeight;
    }

    function setTyping(on) {
        if (typing) typing.className = 'agent-chat__typing' + (on ? ' is-on' : '');
        if (on) log.scrollTop = log.scrollHeight;
    }

    function showHint(text) {
        if (!hint || !text) return;
        hint.textContent = text;
        hint.hidden = false;
        if (hintTimer) clearTimeout(hintTimer);
        hintTimer = setTimeout(function () { hint.hidden = true; }, 5000);
    }

    // ---- chat round-trip -------------------------------------------------

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        send();
    });

    function send() {
        var msg = (input.value || '').trim();
        if (!msg || sending) return;
        input.value = '';
        addMsg('user', msg);
        sending = true;
        setTyping(true);

        fetch(cfg.endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': cfg.csrf,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ message: msg, session_key: sessionKey })
        }).then(function (r) {
            if (r.status === 403) throw { kind: 'unavailable' };
            if (r.status === 429) throw { kind: 'busy' };
            if (!r.ok) throw { kind: 'server' };
            return r.json();
        }).then(function (d) {
            if (d.session_key) {
                sessionKey = d.session_key;
                try { sessionStorage.setItem(sessionStoreKey, sessionKey); } catch (e2) { /* ignore */ }
            }
            setTyping(false);
            addMsg('bot', d.reply || S.connection || 'Error');
            if (d.order) addOrderCard(d.order);
        }).catch(function (err) {
            setTyping(false);
            if (err && err.kind === 'unavailable') addMsg('bot', S.unavailable || 'Chat is unavailable right now.');
            else if (err && err.kind === 'busy') showHint(S.busy || 'Too many messages — please wait a moment.');
            else showHint(S.connection || 'Connection problem — please try again.');
        }).then(function () {
            sending = false;
        });
    }

    // ---- voice input (layered) --------------------------------------------

    var SR = window.SpeechRecognition || window.webkitSpeechRecognition || null;
    var canRecord = !!(cfg.stt && navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
    var micDenied = false;
    try { micDenied = sessionStorage.getItem(micDeniedKey) === '1'; } catch (e) { /* ignore */ }

    var voiceMode = null; // 'speech' | 'record' | null
    if (!micDenied) {
        if (SR) voiceMode = 'speech';
        else if (canRecord) voiceMode = 'record';
    }
    if (!mic) return;
    if (!voiceMode) return; // layer 3: no STT → mic stays hidden, chat text works.

    mic.hidden = false;

    var recognizing = null;   // active SpeechRecognition
    var recorder = null;      // active MediaRecorder
    var recorderStream = null;
    var recorderTimer = null;
    var recorderTick = null;
    var busy = false;

    mic.addEventListener('click', function () {
        if (busy) return;
        if (recognizing) { try { recognizing.stop(); } catch (e) { } return; }
        if (recorder) { stopRecorder(); return; }
        if (voiceMode === 'speech') startSpeech();
        else startRecorder();
    });

    function micUi(state, label) {
        mic.className = 'agent-chat__mic' + (state === 'live' ? ' is-live' : state === 'busy' ? ' is-busy' : '');
        mic.setAttribute('aria-pressed', state === 'live' ? 'true' : 'false');
        var l = label || S.micStart || 'Use voice input';
        mic.setAttribute('aria-label', l);
        mic.title = l;
    }

    function denyMic(message) {
        try { sessionStorage.setItem(micDeniedKey, '1'); } catch (e) { /* ignore */ }
        mic.hidden = true;
        voiceMode = null;
        showHint(message || S.micDenied || 'Microphone access was denied — you can type instead.');
    }

    /* Hard failure of the Web Speech layer (WebViews, no speech service):
       fall through to the recorder layer when available, else hide for good. */
    function speechFellThrough() {
        recognizing = null;
        micUi('idle');
        if (canRecord) { voiceMode = 'record'; startRecorder(); }
        else denyMic(S.micFailed);
    }

    // Layer 1 — Web Speech API.
    function startSpeech() {
        var r;
        try {
            r = new SR();
        } catch (e) { speechFellThrough(); return; }

        var base = input.value ? input.value.replace(/\s+$/, '') + ' ' : '';
        var finalText = '';
        var gotResult = false;
        var failed = false;

        r.lang = cfg.locale || 'en';
        r.interimResults = true;
        r.continuous = false; // stop on silence
        r.maxAlternatives = 1;

        r.onstart = function () { micUi('live', S.micStop); showHint(S.listening || 'Listening…'); };
        r.onresult = function (ev) {
            gotResult = true;
            var interim = '';
            for (var i = ev.resultIndex; i < ev.results.length; i++) {
                if (ev.results[i].isFinal) finalText += ev.results[i][0].transcript;
                else interim += ev.results[i][0].transcript;
            }
            input.value = base + (finalText + interim).trim();
        };
        r.onerror = function (ev) {
            failed = true;
            recognizing = null;
            if (ev.error === 'not-allowed' || ev.error === 'service-not-allowed') {
                if (ev.error === 'not-allowed') denyMic(S.micDenied);
                else speechFellThrough();
            } else if (ev.error === 'no-speech' || ev.error === 'aborted') {
                micUi('idle'); // quiet stop — nothing said.
            } else {
                speechFellThrough(); // network / audio-capture / unknown.
            }
        };
        r.onend = function () {
            if (failed) return;
            recognizing = null;
            micUi('idle');
            input.value = base + finalText.trim() || input.value;
            if (gotResult) input.focus();
        };

        recognizing = r;
        try { r.start(); } catch (e) { speechFellThrough(); }
    }

    // Layer 2 — MediaRecorder → Track B agent.stt endpoint.
    function pickMime() {
        var candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'];
        for (var i = 0; i < candidates.length; i++) {
            if (window.MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(candidates[i])) return candidates[i];
        }
        return ''; // let the browser pick (iOS Safari → mp4).
    }

    function startRecorder() {
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            var mime = pickMime();
            var rec;
            try {
                rec = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream);
            } catch (e) {
                stream.getTracks().forEach(function (t) { t.stop(); });
                denyMic(S.micFailed);
                return;
            }
            var chunks = [];
            var secondsLeft = cfg.maxRecordSeconds || 15;

            rec.ondataavailable = function (ev) { if (ev.data && ev.data.size) chunks.push(ev.data); };
            rec.onstop = function () {
                cleanupRecorder();
                var blob = new Blob(chunks, { type: rec.mimeType || mime || 'audio/webm' });
                if (!blob.size) { micUi('idle'); return; }
                if (blob.size > (cfg.maxUploadBytes || 2097152)) {
                    micUi('idle');
                    showHint(S.tooLarge || 'Recording is too large — try a shorter message.');
                    return;
                }
                transcribe(blob);
            };

            recorder = rec;
            recorderStream = stream;
            rec.start(250);
            micUi('live', S.micStop);
            showHint((S.recording || 'Recording…') + ' ' + secondsLeft + 's');
            recorderTick = setInterval(function () {
                secondsLeft--;
                if (secondsLeft > 0) showHint((S.recording || 'Recording…') + ' ' + secondsLeft + 's');
            }, 1000);
            recorderTimer = setTimeout(stopRecorder, (cfg.maxRecordSeconds || 15) * 1000); // ≤15s hard stop.
        }).catch(function (err) {
            if (err && (err.name === 'NotAllowedError' || err.name === 'SecurityError')) denyMic(S.micDenied);
            else denyMic(S.micFailed);
        });
    }

    function stopRecorder() {
        if (recorder && recorder.state !== 'inactive') {
            try { recorder.stop(); } catch (e) { cleanupRecorder(); micUi('idle'); }
        }
    }

    function cleanupRecorder() {
        if (recorderTimer) { clearTimeout(recorderTimer); recorderTimer = null; }
        if (recorderTick) { clearInterval(recorderTick); recorderTick = null; }
        if (recorderStream) {
            recorderStream.getTracks().forEach(function (t) { t.stop(); });
            recorderStream = null;
        }
        recorder = null;
    }

    function transcribe(blob) {
        busy = true;
        micUi('busy', S.transcribing);
        showHint(S.transcribing || 'Transcribing…');
        var fd = new FormData();
        var ext = (blob.type.indexOf('mp4') !== -1) ? 'm4a' : (blob.type.indexOf('ogg') !== -1 ? 'ogg' : 'webm');
        fd.append('audio', blob, 'utterance.' + ext);

        fetch(cfg.stt, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' },
            body: fd
        }).then(function (r) {
            if (!r.ok) throw new Error('stt ' + r.status);
            return r.json();
        }).then(function (d) {
            // Transcript goes into the input for the customer to review/edit —
            // the read-back confirm gate stays with the agent, never bypassed.
            if (d && d.text) {
                input.value = (input.value ? input.value.replace(/\s+$/, '') + ' ' : '') + d.text;
                input.focus();
                if (hint) hint.hidden = true;
            } else {
                showHint(S.sttFailed || 'Could not transcribe audio — please type instead.');
            }
        }).catch(function () {
            showHint(S.sttFailed || 'Could not transcribe audio — please type instead.');
        }).then(function () {
            busy = false;
            micUi('idle');
        });
    }
})();
