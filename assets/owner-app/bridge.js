// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Owner app — Hotwire Native bridge components (server item S5).
 * Contract + native message formats: rev/mobile-app/BRIDGE.md (keep in sync).
 *
 * Loaded ONLY inside the native app (layouts/app.blade.php, OwnerApp::inApp()),
 * as an ES module with data-turbo-eval="false": a module runs once per document,
 * and Stimulus connects / disconnects the controllers itself on every Turbo visit.
 * Each controller loads only when the app's user agent lists its component
 * ("bridge-components: [form menu button]"), so a shell without a component
 * leaves the web control untouched.
 *
 *   bridge--form     native nav-bar Save/Submit that clicks the form's submit target
 *   bridge--menu     native action sheet built from item elements; a pick clicks the item
 *   bridge--button   native nav-bar button; a tap clicks the element
 *   bridge--purchase in-app purchase (StoreKit 2 / Play Billing) presence marker; the
 *                    billing page script sends products / buy / restore itself
 */
import { Application } from './vendor/stimulus-3.2.2.js';
import { BridgeComponent, BridgeElement } from './vendor/hotwire-native-bridge-1.2.2.js';

function isDisabled(el) {
  return !!el && (el.disabled === true || el.getAttribute('aria-disabled') === 'true' || el.classList.contains('disabled'));
}

// BridgeElement#title throws when an element has no title, label, text or value.
function titleOf(el) {
  try { return new BridgeElement(el).title || ''; } catch (e) { return ''; }
}

function isTruthyAttribute(el, name) {
  if (!el.hasAttribute(name)) return false;
  var value = el.getAttribute(name);
  return value !== 'false' && value !== '0';
}

// ─── form ────────────────────────────────────────────────────────────────────
// <form data-controller="bridge--form"> … <button type="submit" data-bridge--form-target="submit"
//        data-bridge-title="Save">Save</button></form>
// connect {submitTitle} → native bar button; its tap replies to "connect" → click
// the submit target. submitDisabled / submitEnabled follow the web button's
// disabled state and any in-flight Turbo submission.
class FormController extends BridgeComponent {
  static component = 'form';
  static targets = ['submit'];

  connect() {
    super.connect();
    this.submitting = false;
    this.lastSent = null;
    this.onSubmitStart = this.onSubmitStart || (() => { this.submitting = true; this.sync(); });
    this.onSubmitEnd = this.onSubmitEnd || (() => { this.submitting = false; this.sync(); });
    this.onNativeSubmit = this.onNativeSubmit || ((event) => {
      // A submit that nobody intercepted (a data-turbo="false" form) leaves the
      // page: grey the native button so it cannot be tapped twice. Checked after
      // the dispatch, because Turbo / data-ajax handlers prevent it later on.
      setTimeout(() => { if (!event.defaultPrevented) { this.submitting = true; this.sync(); } }, 0);
    });
    this.element.removeEventListener('turbo:submit-start', this.onSubmitStart);
    this.element.removeEventListener('turbo:submit-end', this.onSubmitEnd);
    this.element.removeEventListener('submit', this.onNativeSubmit);
    this.element.addEventListener('turbo:submit-start', this.onSubmitStart);
    this.element.addEventListener('turbo:submit-end', this.onSubmitEnd);
    this.element.addEventListener('submit', this.onNativeSubmit);
    this.notifyBridgeOfConnect();
  }

  disconnect() {
    this.element.removeEventListener('turbo:submit-start', this.onSubmitStart);
    this.element.removeEventListener('turbo:submit-end', this.onSubmitEnd);
    this.element.removeEventListener('submit', this.onNativeSubmit);
    this.stopObserving();
    this.connected = false;
    super.disconnect();
  }

  // The button can be rendered later or re-rendered (Alpine x-if, a wizard's last
  // step): announce the new one; while there is none the native button is greyed.
  submitTargetConnected() { if (this.connected) this.notifyBridgeOfConnect(); }
  submitTargetDisconnected() {
    this.stopObserving();
    if (this.connected && !this.hasSubmitTarget && this.lastSent !== false) {
      this.lastSent = false;
      this.send('submitDisabled');
    }
  }

  notifyBridgeOfConnect() {
    this.connected = true;
    if (!this.hasSubmitTarget) return;
    var submitTitle = titleOf(this.submitTarget);
    this.lastSent = null;
    this.send('connect', { submitTitle: submitTitle }, () => this.submitFromNative());
    this.observe(this.submitTarget);
    this.sync();
  }

  submitFromNative() {
    if (!this.hasSubmitTarget || this.submitting || isDisabled(this.submitTarget)) return;
    new BridgeElement(this.submitTarget).click();
  }

  observe(button) {
    this.stopObserving();
    this.observer = new MutationObserver(() => this.sync());
    this.observer.observe(button, { attributes: true, attributeFilter: ['disabled', 'aria-disabled', 'class'] });
  }

  stopObserving() {
    if (this.observer) { this.observer.disconnect(); this.observer = null; }
  }

  sync() {
    if (!this.hasSubmitTarget) return;
    var enabled = !this.submitting && !isDisabled(this.submitTarget);
    // "connect" implies enabled, so only a change (or a disabled start) is sent.
    if (this.lastSent === null && enabled) { this.lastSent = true; return; }
    if (this.lastSent === enabled) return;
    this.lastSent = enabled;
    this.send(enabled ? 'submitEnabled' : 'submitDisabled');
  }
}

// ─── menu ────────────────────────────────────────────────────────────────────
// <div data-controller="bridge--menu" data-bridge-title="Order #12">
//   <button type="button" data-bridge--menu-target="trigger">⋯</button>
//   <a href="…" data-bridge--menu-target="item">Edit</a>
//   <button type="submit" form="del-12" data-bridge--menu-target="item" data-bridge-destructive>Delete</button>
// </div>
// A tap on the trigger opens the NATIVE sheet instead of the web dropdown (the
// click is stopped in the capture phase, so Bootstrap / Alpine never see it).
// display {title, items:[{title,index,destructive}], source} → reply {selectedIndex}
// → click that item (its own confirm dialog / form submit / link runs as on the web).
class MenuController extends BridgeComponent {
  static component = 'menu';
  static targets = ['item', 'trigger'];

  connect() {
    super.connect();
    this.onClickCapture = this.onClickCapture || ((event) => {
      var trigger = event.target && event.target.closest ? event.target.closest('[data-bridge--menu-target~="trigger"]') : null;
      if (!trigger || trigger.closest('[data-controller~="bridge--menu"]') !== this.element) return;
      this.show(event, trigger);
    });
    this.element.removeEventListener('click', this.onClickCapture, true);
    this.element.addEventListener('click', this.onClickCapture, true);
  }

  disconnect() {
    this.element.removeEventListener('click', this.onClickCapture, true);
    super.disconnect();
  }

  // Also usable as a Stimulus action: data-action="bridge--menu#show".
  show(event, trigger) {
    if (!this.enabled) return;
    var items = this.itemTargets
      .map((element, index) => this.menuItem(element, index))
      .filter(Boolean);
    if (!items.length) return; // nothing to offer natively: let the web menu open

    if (event) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
    var source = trigger || (event && event.currentTarget && event.currentTarget !== this.element ? event.currentTarget : (event && event.target)) || this.element;
    var rect = source.getBoundingClientRect ? source.getBoundingClientRect() : { x: 0, y: 0, width: 0, height: 0 };

    this.send('display', {
      title: this.element.getAttribute('data-bridge-title') || '',
      items: items,
      source: { x: rect.x, y: rect.y, width: rect.width, height: rect.height },
    }, (message) => {
      var index = message && message.data ? Number(message.data.selectedIndex) : -1;
      var element = this.itemTargets[index];
      if (element && !isDisabled(element)) new BridgeElement(element).click();
    });
  }

  menuItem(element, index) {
    var bridgeElement = new BridgeElement(element);
    if (bridgeElement.disabled || isDisabled(element)) return null;
    return { title: titleOf(element), index: index, destructive: isTruthyAttribute(element, 'data-bridge-destructive') };
  }
}

// ─── button ──────────────────────────────────────────────────────────────────
// <a href="…/create" data-controller="bridge--button" data-bridge-title="Add item"
//    data-bridge-ios-image="plus" data-bridge-android-image="add">Add item</a>
// connect {title, iosImage, androidImage} → native bar button; its tap replies to
// "connect" → click the element.
class ButtonController extends BridgeComponent {
  static component = 'button';

  connect() {
    super.connect();
    var element = this.bridgeElement;
    this.send('connect', {
      title: titleOf(this.element),
      iosImage: element.bridgeAttribute('ios-image') || null,
      androidImage: element.bridgeAttribute('android-image') || null,
    }, () => {
      if (!isDisabled(this.element)) this.bridgeElement.click();
    });
  }
}

// ─── purchase ──────────────────────────────────────────────────────────────
// The in-app-purchase bridge component. It carries NO web control: it just
// announces, on every page that mounts it, that the page can run purchases, so
// the native shell only takes over where the web is ready. The billing page's
// own script (assets/owner-app/billing.js) then sends `products` / `buy` /
// `restore` / `acknowledge` to this component through window.HotwireNative.web
// and shows the replies. `acknowledge` means the server accepted the verify
// POST, so the shell may now finish the transaction (StoreKit transaction.finish
// / Play acknowledgePurchase) — never on `purchased` / `restored` alone.
// It is gated like the others: it loads only when the app UA lists
// `purchase` (add `purchase` to the shell's registered components).
class PurchaseController extends BridgeComponent {
  static component = 'purchase';

  connect() {
    super.connect();
    this.send('connect', {});
  }
}

if (!window.MBBridge) {
  var application = Application.start(document.documentElement);
  application.register('bridge--form', FormController);
  application.register('bridge--menu', MenuController);
  application.register('bridge--button', ButtonController);
  application.register('bridge--purchase', PurchaseController);
  window.MBBridge = { application: application, components: ['form', 'menu', 'button', 'purchase'] };
}
