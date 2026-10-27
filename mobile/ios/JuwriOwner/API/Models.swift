import Foundation

/// Server contract, IMPLEMENTATION-SPEC §4 (unknown keys are ignored by Decodable).

struct PlatformVersion: Decodable {
    let minVersion: String?
    let latestVersion: String?
    let storeUrl: String?
    let updateRequired: Bool?
    let updateAvailable: Bool?
}

struct AppLinks: Decodable {
    let login: String?
    let register: String?
    let forgotPassword: String?
}

struct ConfigResponse: Decodable {
    let enabled: Bool?
    let versions: [String: PlatformVersion]?
    let links: AppLinks?
}

struct OwnerUser: Codable {
    let id: Int
    let name: String?
    let email: String?
    let username: String?
    let locale: String?
    let dir: String?
}

struct Store: Codable {
    let id: Int
    let title: String
}

struct LoginResponse: Decodable {
    let token: String
    let user: OwnerUser
    let stores: [Store]?
    let defaultStoreId: Int?
}

struct Tab: Codable {
    let key: String
    let label: String
    let icon: String?
    let path: String?
}

struct CurrentStore: Codable {
    let id: Int
    let pushEnabled: Bool?
}

struct MeResponse: Codable {
    let user: OwnerUser
    let stores: [Store]
    let defaultStoreId: Int?
    let store: CurrentStore?
    let tabs: [Tab]
    let more: [Tab]
    let onboardingPath: String?

    var currentStoreID: Int? { store?.id ?? defaultStoreId }
    var isRTL: Bool { user.dir == "rtl" }
}

struct SessionResponse: Decodable {
    let url: URL
}

struct APIErrorBody: Decodable {
    let message: String?
    let code: String?
}

struct APIError: Error, LocalizedError {
    let status: Int
    let code: String?
    let message: String

    var errorDescription: String? { message }
    var needsTwoFactor: Bool { code == "two_factor_required" }
    var isUnauthorized: Bool { status == 401 && !needsTwoFactor }
}

/// No connectivity / transport failure → the native offline screen.
struct OfflineError: Error {}
