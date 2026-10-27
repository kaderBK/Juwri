import UIKit
import UserNotifications
#if canImport(FirebaseCore) && canImport(FirebaseMessaging)
import FirebaseCore
import FirebaseMessaging
#endif

/// Push (spec §3.6) — Firebase Messaging so iOS yields an FCM token (the server
/// only speaks FCM HTTP v1, the platform Firebase project).
///
/// UNTESTED on iOS until the Apple Developer account exists: needs the APNs key
/// (.p8) uploaded to Firebase, the Push Notifications capability + a provisioning
/// profile, and a physical iPhone. `GoogleService-Info.plist` is copied in at build
/// time from ~/juwri-mobile-config (gitignored); without it push stays off.
final class PushManager: NSObject {
    static let shared = PushManager()

    private var storeID: Int?
    private var firebaseReady = false

    func configure(application: UIApplication) {
        UNUserNotificationCenter.current().delegate = self
        #if canImport(FirebaseCore) && canImport(FirebaseMessaging)
        if Bundle.main.path(forResource: "GoogleService-Info", ofType: "plist") != nil {
            FirebaseApp.configure()
            Messaging.messaging().delegate = self
            firebaseReady = true
        }
        #endif
    }

    /// After login, on every cold start and on store switch: pre-prompt → permission → register.
    @MainActor
    func onShellReady(storeID: Int?, pushEnabled: Bool, presenter: UIViewController) {
        guard let storeID, pushEnabled, firebaseReady else { return }
        self.storeID = storeID
        UNUserNotificationCenter.current().getNotificationSettings { settings in
            DispatchQueue.main.async {
                switch settings.authorizationStatus {
                case .authorized, .provisional, .ephemeral:
                    UIApplication.shared.registerForRemoteNotifications()
                    self.sendCurrentToken()
                case .notDetermined:
                    guard !SecureStore.shared.pushPromptShown else { return }
                    SecureStore.shared.pushPromptShown = true
                    let alert = UIAlertController(title: L("push_prompt_title"), message: L("push_prompt_body"), preferredStyle: .alert)
                    alert.addAction(UIAlertAction(title: L("push_prompt_later"), style: .cancel))
                    alert.addAction(UIAlertAction(title: L("push_prompt_allow"), style: .default) { _ in self.requestPermission() })
                    presenter.present(alert, animated: true)
                default:
                    break
                }
            }
        }
    }

    private func requestPermission() {
        UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .badge, .sound]) { granted, _ in
            guard granted else { return }
            DispatchQueue.main.async { UIApplication.shared.registerForRemoteNotifications() }
        }
    }

    func didRegister(apnsToken: Data) {
        #if canImport(FirebaseMessaging)
        if firebaseReady { Messaging.messaging().apnsToken = apnsToken }
        #endif
    }

    private func sendCurrentToken() {
        #if canImport(FirebaseMessaging)
        guard firebaseReady else { return }
        Messaging.messaging().token { token, _ in
            if let token { self.send(token) }
        }
        #endif
    }

    private func send(_ token: String) {
        guard let storeID = storeID ?? SecureStore.shared.storeID, SecureStore.shared.hasToken else { return }
        Task {
            do {
                try await OwnerAPI.shared.registerDevice(storeID: storeID, pushToken: token)
                SecureStore.shared.registeredPushToken = token
            } catch {
                // 403 feature_disabled → no retry until the next cold start; offline → same.
            }
        }
    }
}

#if canImport(FirebaseMessaging)
extension PushManager: MessagingDelegate {
    func messaging(_ messaging: Messaging, didReceiveRegistrationToken fcmToken: String?) {
        if let fcmToken { send(fcmToken) }
    }
}
#endif

extension PushManager: UNUserNotificationCenterDelegate {
    /// Foreground: show the banner.
    func userNotificationCenter(_ center: UNUserNotificationCenter, willPresent notification: UNNotification) async -> UNNotificationPresentationOptions {
        [.banner, .sound, .badge]
    }

    /// Tap: data.url → there; else data.order_id → Orders tab; else Home (spec §3.6).
    func userNotificationCenter(_ center: UNUserNotificationCenter, didReceive response: UNNotificationResponse) async {
        let target = RouteTarget.from(push: response.notification.request.content.userInfo)
        await MainActor.run {
            UIApplication.shared.applicationIconBadgeNumber = 0
            SceneDelegate.current?.open(target)
        }
    }
}
