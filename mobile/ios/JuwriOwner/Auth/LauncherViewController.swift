import LocalAuthentication
import UIKit
import WebKit

/// Native launch / version gate / offline screen (spec §3.2, §3.8):
/// config → (update screen) → token? → Face ID → /me → /session hand-off → tabs.
/// Also runs the session-lost re-entry and the web-logout wipe (LoginGateViewController).
final class LauncherViewController: UIViewController {
    enum Mode { case normal, relogin, logout }

    /// Face ID unlock is per process: store switches and re-entries don't re-prompt.
    static var unlocked = false

    private let mode: Mode
    private var target: RouteTarget?
    private let api = OwnerAPI.shared
    private let store = SecureStore.shared

    private let spinner = UIActivityIndicatorView(style: .large)
    private let titleLabel = UILabel()
    private let messageLabel = UILabel()
    private let actionButton = UIButton(configuration: .filled())
    private var action: (() -> Void)?

    init(mode: Mode, target: RouteTarget?) {
        self.mode = mode
        self.target = target
        super.init(nibName: nil, bundle: nil)
    }

    @available(*, unavailable) required init?(coder: NSCoder) { fatalError() }

    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = .systemBackground
        buildUI()
    }

    override func viewDidAppear(_ animated: Bool) {
        super.viewDidAppear(animated)
        if mode == .logout { logoutThenLogin() } else { start() }
    }

    // MARK: flow

    private func start() {
        showLoading()
        Task { @MainActor in
            do {
                let config = try await api.config()
                if config.enabled == false {
                    return showState(L("app_disabled_title"), L("app_disabled_body"), button: nil, action: nil)
                }
                let version = config.versions?[AppConfig.platform]
                if version?.updateRequired == true {
                    let storeURL = URL(string: version?.storeUrl ?? "")
                    return showState(L("update_title"), L("update_body"), button: storeURL == nil ? nil : L("update_action")) {
                        if let storeURL { UIApplication.shared.open(storeURL) }
                    }
                }
                let updateAvailable = version?.updateAvailable == true

                guard store.hasToken else { return openLogin() }
                if !Self.unlocked {
                    guard await unlockWithBiometrics() else {
                        return showState(L("unlock_title"), L("unlock_body"), button: L("unlock_action")) { [weak self] in self?.start() }
                    }
                    Self.unlocked = true
                }
                try await enterDashboard(updateAvailable: updateAvailable)
            } catch is OfflineError {
                showOffline()
            } catch let error as APIError {
                if error.isUnauthorized || ["account_blocked", "admin_not_supported", "app_token_required"].contains(error.code ?? "") {
                    // Token dead / revoked on the dashboard → native login (spec A1 DONE check).
                    await signOutLocally()
                    openLogin(notice: error.status == 403 ? error.message : nil)
                } else {
                    showState(L("error_title"), error.message, button: L("retry")) { [weak self] in self?.start() }
                }
            } catch {
                showState(L("error_title"), error.localizedDescription, button: L("retry")) { [weak self] in self?.start() }
            }
        }
    }

    private func enterDashboard(updateAvailable: Bool) async throws {
        let me = try await api.me(storeID: store.storeID)
        store.storeID = me.currentStoreID
        UserDefaults.standard.set(me.user.locale?.split(separator: "-").first.map(String.init), forKey: "owner_locale")

        let firstPath = me.tabs.first(where: { $0.path != nil })?.path ?? me.onboardingPath ?? "/dashboard"
        let session = try await api.session(to: firstPath, storeID: me.currentStoreID)
        guard let landing = try await api.handoff(session.url) else {
            await signOutLocally()
            return openLogin()
        }
        SceneDelegate.current?.showShell(me: me, landing: landing, target: target, updateAvailable: updateAvailable)
    }

    /// Web logout landed on /login?app_logout=1 (spec §3.2 step 8).
    private func logoutThenLogin() {
        showLoading()
        Task { @MainActor in
            try? await api.logout(pushToken: store.registeredPushToken)
            await signOutLocally()
            openLogin()
        }
    }

    private func signOutLocally() async {
        store.wipe()
        Self.unlocked = false
        await Self.clearWebData()
    }

    /// Wipes the ONE cookie store all web views share, plus web storage (rule 7).
    @MainActor static func clearWebData() async {
        let types = WKWebsiteDataStore.allWebsiteDataTypes()
        await WKWebsiteDataStore.default().removeData(ofTypes: types, modifiedSince: .distantPast)
        HTTPCookieStorage.shared.removeCookies(since: .distantPast)
    }

    private func openLogin(notice: String? = nil) {
        SceneDelegate.current?.showLogin(target: target, notice: notice)
    }

    // MARK: Face ID (spec §3.2 step 2)

    private func unlockWithBiometrics() async -> Bool {
        let context = LAContext()
        var error: NSError?
        // No passcode / biometrics enrolled → nothing to gate with.
        guard context.canEvaluatePolicy(.deviceOwnerAuthentication, error: &error) else { return true }
        return (try? await context.evaluatePolicy(.deviceOwnerAuthentication, localizedReason: L("unlock_body"))) ?? false
    }

    // MARK: UI states

    private func buildUI() {
        let logo = UIImageView(image: UIImage(systemName: "qrcode"))
        logo.tintColor = .label
        logo.contentMode = .scaleAspectFit
        logo.isAccessibilityElement = false
        titleLabel.font = .preferredFont(forTextStyle: .title2)
        titleLabel.textAlignment = .center
        titleLabel.numberOfLines = 0
        messageLabel.font = .preferredFont(forTextStyle: .body)
        messageLabel.textColor = .secondaryLabel
        messageLabel.textAlignment = .center
        messageLabel.numberOfLines = 0
        actionButton.addAction(UIAction { [weak self] _ in self?.action?() }, for: .touchUpInside)

        let stack = UIStackView(arrangedSubviews: [logo, spinner, titleLabel, messageLabel, actionButton])
        stack.axis = .vertical
        stack.alignment = .center
        stack.spacing = 16
        stack.translatesAutoresizingMaskIntoConstraints = false
        view.addSubview(stack)
        NSLayoutConstraint.activate([
            logo.widthAnchor.constraint(equalToConstant: 64), logo.heightAnchor.constraint(equalToConstant: 64),
            actionButton.heightAnchor.constraint(greaterThanOrEqualToConstant: 50),
            stack.centerYAnchor.constraint(equalTo: view.safeAreaLayoutGuide.centerYAnchor),
            stack.leadingAnchor.constraint(equalTo: view.layoutMarginsGuide.leadingAnchor, constant: 16),
            stack.trailingAnchor.constraint(equalTo: view.layoutMarginsGuide.trailingAnchor, constant: -16),
        ])
    }

    private func showLoading() {
        spinner.startAnimating()
        spinner.isHidden = false
        [titleLabel, messageLabel, actionButton].forEach { $0.isHidden = true }
    }

    private func showOffline() {
        showState(L("offline_title"), L("offline_body"), button: L("retry")) { [weak self] in self?.start() }
    }

    private func showState(_ title: String, _ message: String?, button: String?, action: (() -> Void)?) {
        spinner.stopAnimating()
        spinner.isHidden = true
        titleLabel.isHidden = false
        titleLabel.text = title
        messageLabel.isHidden = (message ?? "").isEmpty
        messageLabel.text = message
        actionButton.isHidden = button == nil
        actionButton.setTitle(button, for: .normal)
        self.action = action
    }
}

/// Localized native string (Resources/{en,ar}.lproj/Localizable.strings) in the OWNER's
/// dashboard language (/me user.locale, remembered for the next login screen), falling
/// back to the device language.
func L(_ key: String) -> String {
    if let lang = UserDefaults.standard.string(forKey: "owner_locale"),
       let path = Bundle.main.path(forResource: lang, ofType: "lproj"),
       let bundle = Bundle(path: path) {
        return bundle.localizedString(forKey: key, value: nil, table: nil)
    }
    return NSLocalizedString(key, comment: "")
}
