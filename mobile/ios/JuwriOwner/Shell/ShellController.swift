import HotwireNative
import SafariServices
import UIKit

/// The shell (spec §3.5): up to four web tabs from `/me` — one Navigator per tab,
/// one web view each — plus a native "More" sheet from `/me.more` with the store
/// picker. A store switch goes back through the launcher (fresh /me, fresh
/// session, push re-registered) and rebuilds the tabs.
final class ShellController: UITabBarController, UITabBarControllerDelegate {
    private let me: MeResponse
    private var pending: RouteTarget?
    private var updateAvailable: Bool
    private var firstAppearance = true
    private var entries: [(tab: Tab, navigator: Navigator)] = []
    private var started = Set<Int>()
    private let moreItem = UIViewController()

    init(me: MeResponse, landing: URL, target: RouteTarget?, updateAvailable: Bool) {
        self.me = me
        self.pending = target
        self.updateAvailable = updateAvailable

        var web = me.tabs.filter { $0.path != nil }.prefix(4).map { $0 }
        if web.isEmpty { // no store yet → onboarding (spec §4 /me)
            web = [Tab(key: "home", label: L("tab_home"), icon: "home", path: me.onboardingPath ?? "/dashboard")]
        }
        // Built BEFORE super.init: UITabBarController loads its view (viewDidLoad) during init.
        entries = web.enumerated().map { index, tab in
            let start = index == 0 ? landing : AppConfig.url(tab.path!)
            return (tab, Navigator(configuration: .init(name: "tab-\(tab.key)", startLocation: start)))
        }
        super.init(nibName: nil, bundle: nil)
        entries.forEach { $0.navigator.delegate = self }
        setUpTabs()
    }

    @available(*, unavailable) required init?(coder: NSCoder) { fatalError() }

    var activeNavigator: Navigator { entries[min(selectedIndex, entries.count - 1)].navigator }

    private func setUpTabs() {
        delegate = self
        // RTL mirroring for Arabic / Urdu owners, independent of the device language.
        let direction: UISemanticContentAttribute = me.isRTL ? .forceRightToLeft : .forceLeftToRight
        // Also for views created later (modal navigation controllers, sheets, alerts).
        UIView.appearance().semanticContentAttribute = direction
        view.semanticContentAttribute = direction
        tabBar.semanticContentAttribute = direction

        var controllers: [UIViewController] = entries.map { entry in
            let nav = entry.navigator.rootViewController
            nav.view.semanticContentAttribute = direction
            nav.navigationBar.semanticContentAttribute = direction
            nav.tabBarItem = UITabBarItem(title: entry.tab.label, image: Icons.image(for: entry.tab.icon), selectedImage: nil)
            return nav
        }
        // Always present: the More sheet also holds Log out.
        let moreTab = me.tabs.first(where: { $0.path == nil })
        moreItem.tabBarItem = UITabBarItem(title: moreTab?.label ?? L("tab_more"), image: Icons.image(for: moreTab?.icon ?? "menu"), selectedImage: nil)
        controllers.append(moreItem)
        viewControllers = controllers
        selectedIndex = 0
        startTab(0)
    }

    override func viewDidAppear(_ animated: Bool) {
        super.viewDidAppear(animated)
        if firstAppearance {
            firstAppearance = false
            PushManager.shared.onShellReady(storeID: me.currentStoreID, pushEnabled: me.store?.pushEnabled == true, presenter: self)
        }
        if let target = pending {
            pending = nil
            route(target)
        }
        if updateAvailable {
            updateAvailable = false
            let alert = UIAlertController(title: nil, message: L("update_available"), preferredStyle: .alert)
            alert.addAction(UIAlertAction(title: "OK", style: .default))
            present(alert, animated: true)
        }
    }

    /// Push taps / links (spec §3.6): data.url → there; else a tab (orders / home).
    func route(_ target: RouteTarget) {
        if let url = target.url {
            activeNavigator.route(url)
            return
        }
        if let index = entries.firstIndex(where: { $0.tab.key == target.tabKey }) {
            selectedIndex = index
            startTab(index)
        }
    }

    private func startTab(_ index: Int) {
        guard entries.indices.contains(index), !started.contains(index) else { return }
        started.insert(index)
        entries[index].navigator.start()
    }

    // MARK: UITabBarControllerDelegate

    func tabBarController(_ tabBarController: UITabBarController, shouldSelect viewController: UIViewController) -> Bool {
        if viewController === moreItem {
            showMoreSheet()
            return false
        }
        if viewController === selectedViewController, let index = entries.firstIndex(where: { $0.navigator.rootViewController === viewController }) {
            entries[index].navigator.clearAll(animated: true) // re-tap = back to the tab root
        }
        UISelectionFeedbackGenerator().selectionChanged()
        return true
    }

    func tabBarController(_ tabBarController: UITabBarController, didSelect viewController: UIViewController) {
        if let index = entries.firstIndex(where: { $0.navigator.rootViewController === viewController }) {
            startTab(index)
        }
    }

    // MARK: More sheet + store picker (spec §3.5)

    private func showMoreSheet() {
        let sheet = UIAlertController(title: nil, message: nil, preferredStyle: .actionSheet)
        if me.stores.count > 1 {
            let current = me.stores.first(where: { $0.id == me.currentStoreID })?.title ?? ""
            sheet.addAction(UIAlertAction(title: String(format: L("switch_store_current"), current), style: .default) { [weak self] _ in
                self?.showStorePicker()
            })
        }
        for item in me.more {
            guard let path = item.path else { continue }
            sheet.addAction(UIAlertAction(title: item.label, style: .default) { [weak self] _ in
                self?.activeNavigator.route(AppConfig.url(path))
            })
        }
        // The web header (with its Log out) is hidden in the app (S2), so sign-out is native.
        sheet.addAction(UIAlertAction(title: L("logout"), style: .destructive) { [weak self] _ in
            let confirm = UIAlertController(title: nil, message: L("logout_confirm"), preferredStyle: .alert)
            confirm.addAction(UIAlertAction(title: L("cancel"), style: .cancel))
            confirm.addAction(UIAlertAction(title: L("logout"), style: .destructive) { _ in
                SceneDelegate.current?.showLauncher(mode: .logout)
            })
            self?.present(confirm, animated: true)
        })
        sheet.addAction(UIAlertAction(title: L("cancel"), style: .cancel))
        if let popover = sheet.popoverPresentationController {
            popover.sourceView = tabBar
            popover.sourceRect = tabBar.bounds
        }
        present(sheet, animated: true)
    }

    private func showStorePicker() {
        let picker = UIAlertController(title: L("switch_store"), message: nil, preferredStyle: .actionSheet)
        for store in me.stores {
            let isCurrent = store.id == me.currentStoreID
            picker.addAction(UIAlertAction(title: (isCurrent ? "✓ " : "") + store.title, style: .default) { _ in
                guard !isCurrent else { return }
                SecureStore.shared.storeID = store.id
                SceneDelegate.current?.showLauncher() // fresh /me → new tabs, new session, push re-registered
            })
        }
        picker.addAction(UIAlertAction(title: L("cancel"), style: .cancel))
        if let popover = picker.popoverPresentationController {
            popover.sourceView = tabBar
            popover.sourceRect = tabBar.bounds
        }
        present(picker, animated: true)
    }
}

// MARK: - NavigatorDelegate

extension ShellController: NavigatorDelegate {
    func handle(proposal: VisitProposal, from navigator: Navigator) -> ProposalResult {
        // Path configuration `^/login` → view_controller "login" (spec §3.2 steps 7–8, §3.4).
        if proposal.viewController == LoginGateViewController.pathConfigurationIdentifier {
            let loggedOut = URLComponents(url: proposal.url, resolvingAgainstBaseURL: false)?
                .queryItems?.contains(where: { $0.name == "app_logout" && $0.value == "1" }) == true
            DispatchQueue.main.async {
                SceneDelegate.current?.showLauncher(mode: loggedOut ? .logout : .relogin)
            }
            return .acceptCustom(LoginGateViewController())
        }
        return .accept
    }

    #if HOTWIRE_LEGACY
    func visitableDidFailRequest(_ visitable: Visitable, error: Error, retryHandler: RetryBlock?) {
        presentError(visitable, error, retryHandler)
    }
    #else
    func visitableDidFailRequest(_ visitable: any Visitable, error: HotwireNativeError, retryHandler: RetryBlock?) {
        presentError(visitable, error, retryHandler)
    }
    #endif

    /// Native offline / error screen with Retry (spec §3.8).
    private func presentError(_ visitable: Visitable, _ error: Error, _ retry: RetryBlock?) {
        guard let vc = visitable as? UIViewController else { return }
        OfflineView.present(on: vc, error: error) { retry?() }
    }
}

/// Placeholder destination for the `login` path rule; the launcher replaces the whole shell.
final class LoginGateViewController: UIViewController, PathConfigurationIdentifiable {
    static var pathConfigurationIdentifier: String { "login" }

    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = .systemBackground
        let spinner = UIActivityIndicatorView(style: .large)
        spinner.startAnimating()
        spinner.translatesAutoresizingMaskIntoConstraints = false
        view.addSubview(spinner)
        NSLayoutConstraint.activate([
            spinner.centerXAnchor.constraint(equalTo: view.centerXAnchor),
            spinner.centerYAnchor.constraint(equalTo: view.centerYAnchor),
        ])
    }
}
