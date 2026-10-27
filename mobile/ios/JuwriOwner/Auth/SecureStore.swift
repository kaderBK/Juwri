import Foundation
import Security

/// The app token lives ONLY in the Keychain (AGENT-KICKOFF rule 3), this-device-only,
/// never in UserDefaults, logs or URLs. Face ID / Touch ID gates entering the
/// dashboard at launch (LauncherViewController). Non-secret install state (device
/// id, selected store, last registered push token) lives in UserDefaults.
final class SecureStore {
    static let shared = SecureStore()

    private let service = "com.juwri.owner.app-token"
    private let account = "owner"
    private let defaults = UserDefaults.standard

    // MARK: token (secret)

    var hasToken: Bool { readToken() != nil }

    func readToken() -> String? {
        var query = baseQuery()
        query[kSecReturnData as String] = true
        query[kSecMatchLimit as String] = kSecMatchLimitOne
        var item: CFTypeRef?
        guard SecItemCopyMatching(query as CFDictionary, &item) == errSecSuccess,
              let data = item as? Data else { return nil }
        return String(data: data, encoding: .utf8)
    }

    /// Returns false when the Keychain refused the write (e.g. an unsigned build without
    /// keychain entitlements) — the caller must not pretend the owner is signed in.
    @discardableResult
    func writeToken(_ token: String) -> Bool {
        SecItemDelete(baseQuery() as CFDictionary)
        var query = baseQuery()
        query[kSecValueData as String] = Data(token.utf8)
        query[kSecAttrAccessible as String] = kSecAttrAccessibleWhenUnlockedThisDeviceOnly
        return SecItemAdd(query as CFDictionary, nil) == errSecSuccess
    }

    func clearToken() {
        SecItemDelete(baseQuery() as CFDictionary)
    }

    // MARK: install state (not secret)

    /// Stable per-install UUID (spec §3.2 step 4).
    var deviceID: String {
        if let id = defaults.string(forKey: "device_id") { return id }
        let id = UUID().uuidString
        defaults.set(id, forKey: "device_id")
        return id
    }

    var storeID: Int? {
        get { let v = defaults.integer(forKey: "store_id"); return v > 0 ? v : nil }
        set { defaults.set(newValue ?? 0, forKey: "store_id") }
    }

    /// FCM token last sent to POST /devices (a device address, not a credential).
    var registeredPushToken: String? {
        get { defaults.string(forKey: "push_token") }
        set { defaults.set(newValue, forKey: "push_token") }
    }

    var pushPromptShown: Bool {
        get { defaults.bool(forKey: "push_prompt_shown") }
        set { defaults.set(newValue, forKey: "push_prompt_shown") }
    }

    func wipe() {
        clearToken()
        defaults.removeObject(forKey: "store_id")
        defaults.removeObject(forKey: "push_token")
    }

    /// First launch after a reinstall: the Keychain survives app deletion on iOS, so
    /// drop a token left by a previous install.
    func resetIfReinstalled() {
        if !defaults.bool(forKey: "installed") {
            clearToken()
            defaults.set(true, forKey: "installed")
        }
    }

    private func baseQuery() -> [String: Any] {
        [kSecClass as String: kSecClassGenericPassword,
         kSecAttrService as String: service,
         kSecAttrAccount as String: account]
    }
}
