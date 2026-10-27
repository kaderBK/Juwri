import HotwireNative
import UIKit
import WebKit

@main
final class AppDelegate: UIResponder, UIApplicationDelegate {
    func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?) -> Bool {
        SecureStore.shared.resetIfReinstalled()
        configureHotwire()
        PushManager.shared.configure(application: application)
        return true
    }

    func application(_ application: UIApplication, configurationForConnecting connectingSceneSession: UISceneSession, options: UIScene.ConnectionOptions) -> UISceneConfiguration {
        UISceneConfiguration(name: "Default Configuration", sessionRole: connectingSceneSession.role)
    }

    func application(_ application: UIApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
        PushManager.shared.didRegister(apnsToken: deviceToken)
    }

    private func configureHotwire() {
        Hotwire.loadPathConfiguration(from: [
            .file(Bundle.main.url(forResource: "path-configuration", withExtension: "json")!),
            .server(AppConfig.url("/api/v1/app/path-configuration/ios.json")),
        ])

        // Spec §3.9: a PREFIX; the library appends "Hotwire Native iOS; … bridge-components: [form menu button]".
        Hotwire.config.applicationUserAgentPrefix = AppConfig.userAgentPrefix

        // Exactly the components BRIDGE.md §3 specifies (this list ends up in the UA).
        Hotwire.registerBridgeComponents([
            FormComponent.self,
            MenuComponent.self,
            ButtonComponent.self,
        ])

        // Downloads first (SFSafariViewController does not share the web view's cookies),
        // then the app host in-app, other hosts in SFSafariViewController, mailto/tel via the system.
        Hotwire.registerRouteDecisionHandlers([
            DownloadRouteDecisionHandler(),
            AppNavigationRouteDecisionHandler(),
            SafariViewControllerRouteDecisionHandler(),
            SystemNavigationRouteDecisionHandler(),
        ])

        Hotwire.config.backButtonDisplayMode = .minimal
        Hotwire.config.showDoneButtonOnModals = true
        #if !HOTWIRE_LEGACY
        Hotwire.config.hideTabBarWhenPushed = true // spec §3.5 (1.3+)
        #endif
        Hotwire.config.defaultViewController = { url in WebViewController(url: url) }
        Hotwire.config.makeCustomWebView = { configuration in
            let webView = WKWebView(frame: .zero, configuration: configuration)
            webView.allowsLinkPreview = false // spec §3.3
            #if DEBUG
            if #available(iOS 16.4, *) { webView.isInspectable = true }
            #endif
            return webView
        }
        #if DEBUG
        Hotwire.config.debugLoggingEnabled = true
        #endif
    }
}
