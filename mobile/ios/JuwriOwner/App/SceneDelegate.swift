import UIKit

/// One window whose root is swapped between the native launcher / login and the
/// tab shell (spec §3.2). Push taps and universal links arrive here and are routed
/// through the launcher's session check.
final class SceneDelegate: UIResponder, UIWindowSceneDelegate {
    var window: UIWindow?

    static var current: SceneDelegate? {
        UIApplication.shared.connectedScenes.compactMap { $0.delegate as? SceneDelegate }.first
    }

    func scene(_ scene: UIScene, willConnectTo session: UISceneSession, options connectionOptions: UIScene.ConnectionOptions) {
        guard let windowScene = scene as? UIWindowScene else { return }
        let window = UIWindow(windowScene: windowScene)
        self.window = window

        var target: RouteTarget?
        if let activity = connectionOptions.userActivities.first(where: { $0.activityType == NSUserActivityTypeBrowsingWeb }),
           let url = activity.webpageURL {
            target = RouteTarget.from(link: url)
        }
        showLauncher(target: target)
        window.makeKeyAndVisible()
    }

    /// Universal link while running (spec §3.7) → same routing as a push tap.
    func scene(_ scene: UIScene, continue userActivity: NSUserActivity) {
        guard userActivity.activityType == NSUserActivityTypeBrowsingWeb,
              let url = userActivity.webpageURL, let target = RouteTarget.from(link: url) else { return }
        open(target)
    }

    // MARK: root switching

    func showLauncher(mode: LauncherViewController.Mode = .normal, target: RouteTarget? = nil) {
        setRoot(LauncherViewController(mode: mode, target: target))
    }

    func showLogin(target: RouteTarget?, notice: String? = nil) {
        setRoot(UINavigationController(rootViewController: LoginViewController(target: target, notice: notice)))
    }

    func showShell(me: MeResponse, landing: URL, target: RouteTarget?, updateAvailable: Bool) {
        setRoot(ShellController(me: me, landing: landing, target: target, updateAvailable: updateAvailable))
    }

    /// A push tap / link: re-enter through the session check (spec §3.6).
    func open(_ target: RouteTarget) {
        if let shell = window?.rootViewController as? ShellController {
            shell.route(target)
        } else {
            showLauncher(target: target)
        }
    }

    private func setRoot(_ vc: UIViewController) {
        guard let window else { return }
        window.rootViewController = vc
        UIView.transition(with: window, duration: 0.2, options: .transitionCrossDissolve, animations: nil)
    }
}

/// Where a push tap or a universal link wants to go: `data.url` → there;
/// else `data.order_id` → the Orders tab; else Home.
struct RouteTarget {
    let url: URL?
    let tabKey: String?

    static func from(link: URL) -> RouteTarget? {
        AppConfig.isAppURL(link) ? RouteTarget(url: link, tabKey: nil) : nil
    }

    static func from(push userInfo: [AnyHashable: Any]) -> RouteTarget {
        if let raw = userInfo["url"] as? String, !raw.isEmpty {
            let url = AppConfig.url(raw)
            if AppConfig.isAppURL(url) { return RouteTarget(url: url, tabKey: nil) }
        }
        if let order = userInfo["order_id"] as? String, !order.isEmpty { return RouteTarget(url: nil, tabKey: "orders") }
        return RouteTarget(url: nil, tabKey: "home")
    }
}
