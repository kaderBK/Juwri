{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  FEATURES-5 Track C — customer order voice note recorder.

  Shared, theme-agnostic partial. Renders ONLY when the restaurant toggle
  "Accept voice notes on orders" is ON. Self-contained: its own scoped CSS +
  JS module. Records via MediaRecorder (audio/webm;codecs=opus, audio/mp4
  fallback for iOS Safari), 60s / 2MB cap, one note per order. Uploads the blob
  to a session-scoped endpoint BEFORE checkout and stores the returned temp
  token in a hidden input; a fetch/XHR interceptor injects `voice_note_token`
  into the send-order request so it works in every theme (even ones without the
  order-field-forwarder).

  Unsupported browser / permission denied NEVER breaks checkout — the widget
  hides itself and the order proceeds without a voice note.

  Expects `$store` (the restaurant Post) in scope, as all storefront themes do.
--}}
@php $__vnRestaurantId = isset($store) ? (int) $store->id : 0; @endphp
@if($__vnRestaurantId && \Juwri\Modules\Ordering\Http\Controllers\User\VoiceNoteController::acceptEnabled($__vnRestaurantId))
<section class="mbvn-wrap" id="mbvnWrap" dir="{{ (function_exists('get_lang') && in_array(get_lang(), ['ar','ur','he','fa'])) ? 'rtl' : 'auto' }}" hidden
         data-upload-url="{{ route('store.voiceNote.upload', $__vnRestaurantId) }}"
         data-max-seconds="60" data-max-bytes="2097152">
    <label class="mbvn-label">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2M12 19v4M8 23h8"/></svg>
        {{ ___('Voice note') }} ({{ ___('optional') }})
    </label>

    <input type="hidden" name="voice_note_token" id="mbvnToken" value="">

    <div class="mbvn-controls">
        <button type="button" class="mbvn-btn mbvn-rec" id="mbvnRecBtn">
            <span class="mbvn-dot"></span>
            <span id="mbvnRecLabel">{{ ___('Record') }}</span>
        </button>
        <button type="button" class="mbvn-btn mbvn-stop" id="mbvnStopBtn" hidden>
            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>
            {{ ___('Stop') }}
        </button>
        <span class="mbvn-timer" id="mbvnTimer" hidden>0:00</span>

        <span class="mbvn-preview" id="mbvnPreview" hidden>
            <button type="button" class="mbvn-btn mbvn-play" id="mbvnPlayBtn">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                {{ ___('Play') }}
            </button>
            <button type="button" class="mbvn-btn mbvn-del" id="mbvnDelBtn">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>
                {{ ___('Delete') }}
            </button>
            <span class="mbvn-status" id="mbvnStatus"></span>
        </span>
    </div>
    <p class="mbvn-hint" id="mbvnHint">{{ ___('Tap record to leave a short spoken message for the kitchen.') }}</p>
</section>

<style nonce="{{ csp_nonce() }}">
.mbvn-wrap{margin:.75rem 0;padding:.75rem;border:1px solid rgba(128,128,128,.25);border-radius:.6rem;font-size:.9rem}
.mbvn-wrap[dir="rtl"]{text-align:right}
.mbvn-label{display:flex;align-items:center;gap:.4rem;font-weight:600;margin-bottom:.5rem}
.mbvn-controls{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
.mbvn-btn{display:inline-flex;align-items:center;gap:.35rem;padding:.4rem .7rem;border-radius:.5rem;border:1px solid rgba(128,128,128,.35);background:transparent;color:inherit;cursor:pointer;font:inherit;line-height:1}
.mbvn-btn:active{transform:scale(.97)}
.mbvn-rec .mbvn-dot{width:.6rem;height:.6rem;border-radius:50%;background:#e23b3b;display:inline-block}
.mbvn-rec.is-recording{background:rgba(226,59,59,.12);border-color:#e23b3b}
.mbvn-rec.is-recording .mbvn-dot{animation:mbvnPulse 1s infinite}
@keyframes mbvnPulse{0%,100%{opacity:1}50%{opacity:.25}}
.mbvn-timer{font-variant-numeric:tabular-nums;font-weight:600;color:#e23b3b}
.mbvn-preview{display:inline-flex;align-items:center;gap:.5rem;flex-wrap:wrap}
.mbvn-status{font-size:.8rem;opacity:.75}
.mbvn-hint{margin:.5rem 0 0;font-size:.78rem;opacity:.7}
.mbvn-wrap.is-busy .mbvn-btn{opacity:.6;pointer-events:none}
</style>

<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    var wrap = document.getElementById('mbvnWrap');
    if (!wrap) return;

    // Feature detect — unsupported browser: leave hidden, never block checkout.
    var supported = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
    if (!supported) return;
    wrap.hidden = false;

    var UPLOAD_URL = wrap.getAttribute('data-upload-url');
    var MAX_SECONDS = parseInt(wrap.getAttribute('data-max-seconds'), 10) || 60;
    var MAX_BYTES = parseInt(wrap.getAttribute('data-max-bytes'), 10) || 2097152;

    var recBtn = document.getElementById('mbvnRecBtn');
    var recLabel = document.getElementById('mbvnRecLabel');
    var stopBtn = document.getElementById('mbvnStopBtn');
    var timerEl = document.getElementById('mbvnTimer');
    var preview = document.getElementById('mbvnPreview');
    var playBtn = document.getElementById('mbvnPlayBtn');
    var delBtn = document.getElementById('mbvnDelBtn');
    var statusEl = document.getElementById('mbvnStatus');
    var hintEl = document.getElementById('mbvnHint');
    var tokenInput = document.getElementById('mbvnToken');

    var T = {
        record: @json(___('Record')),
        reRecord: @json(___('Re-record')),
        permDenied: @json(___('Microphone access was blocked. You can still order without a voice note.')),
        tooLong: @json(___('The recording is too long.')),
        tooBig: @json(___('The recording is too large.')),
        uploading: @json(___('Saving…')),
        saved: @json(___('Voice note attached')),
        failed: @json(___('Could not save the voice note.')),
        recording: @json(___('Recording…'))
    };

    var mediaRecorder = null, chunks = [], stream = null;
    var blob = null, blobUrl = null, audio = null;
    var startedAt = 0, tick = null, seconds = 0;

    function fmt(s) { var m = Math.floor(s / 60); var r = s % 60; return m + ':' + (r < 10 ? '0' : '') + r; }

    function pickMime() {
        var prefs = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/aac', 'audio/ogg;codecs=opus', 'audio/ogg'];
        for (var i = 0; i < prefs.length; i++) {
            try { if (window.MediaRecorder.isTypeSupported && window.MediaRecorder.isTypeSupported(prefs[i])) return prefs[i]; } catch (e) {}
        }
        return '';
    }

    function resetTimer() { if (tick) { clearInterval(tick); tick = null; } seconds = 0; timerEl.textContent = '0:00'; }

    function stopStream() { if (stream) { stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} }); stream = null; } }

    function clearRecording() {
        blob = null;
        if (blobUrl) { try { URL.revokeObjectURL(blobUrl); } catch (e) {} blobUrl = null; }
        tokenInput.value = '';
        preview.hidden = true;
        statusEl.textContent = '';
    }

    function startRecording() {
        clearRecording();
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (s) {
            stream = s;
            var mime = pickMime();
            try { mediaRecorder = mime ? new MediaRecorder(s, { mimeType: mime }) : new MediaRecorder(s); }
            catch (e) { mediaRecorder = new MediaRecorder(s); }
            chunks = [];
            mediaRecorder.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
            mediaRecorder.onstop = onStop;
            mediaRecorder.start();

            recBtn.hidden = true; recBtn.classList.add('is-recording');
            stopBtn.hidden = false; timerEl.hidden = false; hintEl.textContent = T.recording;
            startedAt = Date.now(); resetTimer();
            tick = setInterval(function () {
                seconds = Math.floor((Date.now() - startedAt) / 1000);
                timerEl.textContent = fmt(seconds);
                if (seconds >= MAX_SECONDS) stopRecording();
            }, 250);
        }).catch(function () {
            hintEl.textContent = T.permDenied;
            stopStream();
        });
    }

    function stopRecording() {
        if (mediaRecorder && mediaRecorder.state !== 'inactive') { try { mediaRecorder.stop(); } catch (e) {} }
    }

    function onStop() {
        if (tick) { clearInterval(tick); tick = null; }
        stopBtn.hidden = true; recBtn.hidden = false; recBtn.classList.remove('is-recording');
        recLabel.textContent = T.reRecord; timerEl.hidden = false;
        stopStream();

        var type = (mediaRecorder && mediaRecorder.mimeType) ? mediaRecorder.mimeType.split(';')[0] : 'audio/webm';
        blob = new Blob(chunks, { type: type });
        if (blob.size > MAX_BYTES) { hintEl.textContent = T.tooBig; blob = null; return; }
        if (seconds > MAX_SECONDS + 2) { hintEl.textContent = T.tooLong; blob = null; return; }

        blobUrl = URL.createObjectURL(blob);
        preview.hidden = false;
        hintEl.textContent = '';
        upload();
    }

    function extFor(type) {
        if (type.indexOf('webm') > -1) return 'webm';
        if (type.indexOf('mp4') > -1 || type.indexOf('m4a') > -1) return 'm4a';
        if (type.indexOf('aac') > -1) return 'aac';
        if (type.indexOf('ogg') > -1) return 'ogg';
        return 'webm';
    }

    function upload() {
        if (!blob) return;
        wrap.classList.add('is-busy'); statusEl.textContent = T.uploading; tokenInput.value = '';
        var fd = new FormData();
        fd.append('voice_note', blob, 'note.' + extFor(blob.type));
        fd.append('duration', String(seconds));
        var meta = document.querySelector('meta[name="csrf-token"]');
        var headers = { 'X-Requested-With': 'XMLHttpRequest' };
        if (meta) headers['X-CSRF-TOKEN'] = meta.getAttribute('content');
        fetch(UPLOAD_URL, { method: 'POST', body: fd, headers: headers, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
            .then(function (j) {
                wrap.classList.remove('is-busy');
                if (j && j.success && j.token) { tokenInput.value = j.token; statusEl.textContent = T.saved; }
                else { statusEl.textContent = (j && j.message) ? j.message : T.failed; }
            })
            .catch(function () { wrap.classList.remove('is-busy'); statusEl.textContent = T.failed; });
    }

    recBtn.addEventListener('click', startRecording);
    stopBtn.addEventListener('click', stopRecording);
    delBtn.addEventListener('click', function () { clearRecording(); recLabel.textContent = T.record; timerEl.hidden = true; hintEl.textContent = ''; });
    playBtn.addEventListener('click', function () {
        if (!blobUrl) return;
        if (!audio) audio = new Audio();
        audio.src = blobUrl; audio.play().catch(function () {});
    });

    // Inject the token into the send-order request regardless of how the theme's
    // bundle builds it (mirrors the order-field-forwarder, scoped to our field).
    function isSendOrder(u) { return typeof u === 'string' && u.indexOf('/send-order') !== -1; }
    function injectToken(body) {
        var tok = tokenInput.value; if (!tok) return body;
        if (body instanceof FormData) { if (!body.has('voice_note_token')) body.append('voice_note_token', tok); return body; }
        if (body instanceof URLSearchParams) { if (!body.has('voice_note_token')) body.append('voice_note_token', tok); return body; }
        if (typeof body === 'string') {
            try { var j = JSON.parse(body); if (j.voice_note_token == null) { j.voice_note_token = tok; return JSON.stringify(j); } return body; }
            catch (e) { var p = new URLSearchParams(body); if (!p.has('voice_note_token')) p.append('voice_note_token', tok); return p.toString(); }
        }
        return body;
    }
    var of = window.fetch;
    if (of) {
        window.fetch = function (input, init) {
            try {
                var u = (typeof input === 'string') ? input : (input && input.url);
                var m = (init && init.method) || (input && input.method) || 'GET';
                if (String(m).toUpperCase() === 'POST' && isSendOrder(u) && init && 'body' in init) init.body = injectToken(init.body);
            } catch (e) {}
            return of.apply(this, arguments);
        };
    }
    var op = XMLHttpRequest.prototype.open, sd = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, url) { this.__mbvnSend = (String(method).toUpperCase() === 'POST' && isSendOrder(url)); return op.apply(this, arguments); };
    XMLHttpRequest.prototype.send = function (body) { if (this.__mbvnSend) { try { body = injectToken(body); } catch (e) {} } return sd.call(this, body); };
})();
</script>
@endif
