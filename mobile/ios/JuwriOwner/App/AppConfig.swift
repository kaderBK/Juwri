import Foundation

/// Build-time configuration (Config/*.xcconfig → Info.plist). Never hard-code a host elsewhere.
enum AppConfig {
    static let baseURL: URL = {
        let raw = (Bundle.main.object(forInfoDictionaryKey: "MBBaseURL") as? String) ?? ""
        guard let url = URL(string: raw.trimmingCharacters(in: CharacterSet(charactersIn: "/"))), url.host != nil else {
            fatalError("MBBaseURL missing — set MB_BASE_URL in Config/*.xcconfig")
        }
        return url
    }()

    static let platform = "ios"
    static let version = (Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String) ?? "1.0.0"

    /// Spec §3.9 — the server keys `data-owner-app`, the logout marker and S4 off "JuwriApp/".
    static let userAgentPrefix = "JuwriApp/\(version) (\(platform))"

    static func url(_ path: String) -> URL {
        if let absolute = URL(string: path), absolute.scheme != nil { return absolute }
        let p = path.hasPrefix("/") ? path : "/" + path
        return URL(string: baseURL.absoluteString + p)!
    }

    static func isAppURL(_ url: URL) -> Bool {
        (url.scheme == "https" || url.scheme == "http") && url.host?.lowercased() == baseURL.host?.lowercased()
    }
}
