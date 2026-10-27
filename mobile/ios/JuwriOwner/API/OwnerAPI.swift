import UIKit
import WebKit

/// `/api/v1/app/*` client (spec §4). The bearer token is read from the Keychain per
/// call and never logged. Transport failures throw `OfflineError`, server errors `APIError`.
final class OwnerAPI: NSObject {
    static let shared = OwnerAPI()

    private lazy var session: URLSession = {
        let config = URLSessionConfiguration.ephemeral
        config.timeoutIntervalForRequest = 20
        config.httpShouldSetCookies = false
        return URLSession(configuration: config, delegate: self, delegateQueue: nil)
    }()

    private let decoder: JSONDecoder = {
        let d = JSONDecoder()
        d.keyDecodingStrategy = .convertFromSnakeCase
        return d
    }()

    // MARK: endpoints

    func config() async throws -> ConfigResponse {
        try await get("/api/v1/app/config?platform=\(AppConfig.platform)&version=\(AppConfig.version)", auth: false)
    }

    func login(username: String, password: String, otp: String?) async throws -> LoginResponse {
        let device = await Self.device()
        var body: [String: Any] = [
            "username": username, "password": password,
            "device_id": SecureStore.shared.deviceID, "platform": AppConfig.platform,
            "device_name": device.name, "app_version": AppConfig.version,
        ]
        if let otp, !otp.isEmpty { body["otp"] = otp }
        return try await post("/api/v1/app/auth/login", body: body, auth: false)
    }

    func me(storeID: Int?) async throws -> MeResponse {
        try await get("/api/v1/app/me" + (storeID.map { "?store_id=\($0)" } ?? ""), auth: true)
    }

    func session(to: String?, storeID: Int?) async throws -> SessionResponse {
        var body: [String: Any] = [:]
        if let to { body["to"] = to }
        if let storeID { body["store_id"] = storeID }
        return try await post("/api/v1/app/session", body: body, auth: true)
    }

    func registerDevice(storeID: Int, pushToken: String) async throws {
        let device = await Self.device()
        let _: EmptyResponse = try await post("/api/v1/app/devices", body: [
            "store_id": storeID, "token": pushToken, "platform": AppConfig.platform,
            "device_name": device.name, "app_version": AppConfig.version,
            "os_version": device.os,
        ], auth: true)
    }

    func logout(pushToken: String?) async throws {
        var body: [String: Any] = [:]
        if let pushToken { body["push_token"] = pushToken }
        let _: EmptyResponse = try await post("/api/v1/app/auth/logout", body: body, auth: true)
    }

    /// Spec §3.2 step 6, done natively: fetch the single-use hand-off URL WITHOUT
    /// following its redirect, copy the web session cookies it sets into the ONE
    /// cookie store every web view shares (WKWebsiteDataStore.default()), and return
    /// the redirect target — nil when the server bounced to /login.
    func handoff(_ url: URL) async throws -> URL? {
        var request = URLRequest(url: url)
        request.setValue(AppConfig.userAgentPrefix, forHTTPHeaderField: "User-Agent")
        request.setValue("text/html", forHTTPHeaderField: "Accept")
        let (_, response) = try await data(for: request)
        guard let http = response as? HTTPURLResponse else { return nil }

        let headers = http.allHeaderFields.reduce(into: [String: String]()) { acc, pair in
            if let k = pair.key as? String, let v = pair.value as? String { acc[k] = v }
        }
        let cookies = HTTPCookie.cookies(withResponseHeaderFields: headers, for: url)
        await Self.storeCookies(cookies)

        guard let location = http.value(forHTTPHeaderField: "Location"),
              let target = URL(string: location, relativeTo: AppConfig.baseURL)?.absoluteURL else { return nil }
        return target.path.hasSuffix("/login") ? nil : target
    }

    // MARK: plumbing

    /// WebKit's data store must only be touched on the main thread (it traps otherwise).
    @MainActor private static func storeCookies(_ cookies: [HTTPCookie]) async {
        let store = WKWebsiteDataStore.default().httpCookieStore
        for cookie in cookies { await store.setCookie(cookie) }
    }

    @MainActor private static func device() -> (name: String, os: String) {
        (UIDevice.current.name, UIDevice.current.systemVersion)
    }

    private struct EmptyResponse: Decodable {}

    private func get<T: Decodable>(_ path: String, auth: Bool) async throws -> T {
        try await send(URLRequest(url: AppConfig.url(path)), auth: auth)
    }

    private func post<T: Decodable>(_ path: String, body: [String: Any], auth: Bool) async throws -> T {
        var request = URLRequest(url: AppConfig.url(path))
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.httpBody = try JSONSerialization.data(withJSONObject: body)
        return try await send(request, auth: auth)
    }

    private func send<T: Decodable>(_ request: URLRequest, auth: Bool) async throws -> T {
        var request = request
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue(AppConfig.userAgentPrefix, forHTTPHeaderField: "User-Agent")
        if auth {
            guard let token = SecureStore.shared.readToken() else { throw APIError(status: 401, code: "no_token", message: "Not signed in") }
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }
        let (data, response) = try await data(for: request)
        let status = (response as? HTTPURLResponse)?.statusCode ?? 0
        guard (200..<300).contains(status) else {
            let body = try? decoder.decode(APIErrorBody.self, from: data)
            throw APIError(status: status, code: body?.code, message: body?.message ?? "HTTP \(status)")
        }
        if T.self == EmptyResponse.self { return EmptyResponse() as! T }
        return try decoder.decode(T.self, from: data)
    }

    private func data(for request: URLRequest) async throws -> (Data, URLResponse) {
        do {
            return try await session.data(for: request)
        } catch let error as URLError where error.code != .cancelled {
            throw OfflineError()
        }
    }
}

extension OwnerAPI: URLSessionTaskDelegate {
    /// Never follow redirects: the hand-off's Location is read, not visited.
    func urlSession(_ session: URLSession, task: URLSessionTask, willPerformHTTPRedirection response: HTTPURLResponse,
                    newRequest request: URLRequest) async -> URLRequest? {
        nil
    }
}
