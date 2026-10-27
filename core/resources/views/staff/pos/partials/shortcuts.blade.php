{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- T1-7: keyboard-shortcuts help overlay. Toggled by "?" / F1 (or the topbar
     button); rows are static + translatable. Keys are direction-neutral. --}}
<div class="pos-help" x-show="helpOpen" x-cloak @click="helpOpen = false">
    <div class="pos-help-card" @click.stop>
        <header class="pos-help-head">
            <h3>{{ ___('Keyboard shortcuts') }}</h3>
            <button type="button" class="pos-help-x" @click="helpOpen = false">&times;</button>
        </header>
        <ul class="pos-help-list">
            <li><kbd>/</kbd> <kbd>F2</kbd><span>{{ ___('Focus search') }}</span></li>
            <li><kbd>F9</kbd><span>{{ ___('Send to kitchen') }}</span></li>
            <li><kbd>F8</kbd><span>{{ ___('Cash') }}</span></li>
            <li><kbd>F4</kbd><span>{{ ___('Hold') }}</span></li>
            <li><kbd>Esc</kbd><span>{{ ___('Close / clear') }}</span></li>
            <li><kbd>?</kbd> <kbd>F1</kbd><span>{{ ___('Toggle this help') }}</span></li>
        </ul>
        <p class="muted">{{ ___('Scan a barcode any time to add its item.') }}</p>
    </div>
</div>
