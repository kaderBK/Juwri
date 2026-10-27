import HotwireNative
import UIKit
import WebKit

/// CSV / PDF exports (FORMS-AUDIT §5). SFSafariViewController does NOT share the web
/// view's cookies (it would land on /login), so the file is fetched natively with the
/// web view's own session cookies and handed to the share sheet (Save to Files, …).
enum Downloads {
    private static let pattern = try! NSRegularExpression(
        pattern: "(/export(-[a-z]+)?(/|$|\\?))|(/export-csv)|(\\.(csv|pdf|xlsx|zip)(\\?|$))|(/download(/|$|\\?))",
        options: .caseInsensitive
    )

    static func isDownload(_ url: URL) -> Bool {
        guard AppConfig.isAppURL(url) else { return false }
        let s = url.path + (url.query.map { "?\($0)" } ?? "")
        return pattern.firstMatch(in: s, range: NSRange(s.startIndex..., in: s)) != nil
    }

    @MainActor
    static func download(_ url: URL, from presenter: UIViewController) {
        Task { @MainActor in
            do {
                let cookies = await WKWebsiteDataStore.default().httpCookieStore.allCookies()
                var request = URLRequest(url: url)
                request.allHTTPHeaderFields = HTTPCookie.requestHeaderFields(with: cookies.filter { $0.domain.contains(url.host ?? "") })
                request.setValue(Hotwire.config.userAgent, forHTTPHeaderField: "User-Agent")
                let (data, response) = try await URLSession.shared.data(for: request)
                guard let http = response as? HTTPURLResponse, (200..<300).contains(http.statusCode),
                      !(http.mimeType ?? "").contains("html") else { throw URLError(.badServerResponse) }
                let name = http.suggestedFilename ?? url.lastPathComponent
                let file = FileManager.default.temporaryDirectory.appendingPathComponent(name)
                try data.write(to: file, options: .atomic)
                let share = UIActivityViewController(activityItems: [file], applicationActivities: nil)
                share.popoverPresentationController?.sourceView = presenter.view
                presenter.present(share, animated: true)
            } catch {
                let alert = UIAlertController(title: L("download_failed"), message: nil, preferredStyle: .alert)
                alert.addAction(UIAlertAction(title: "OK", style: .default))
                presenter.present(alert, animated: true)
            }
        }
    }
}

/// Export links followed by Turbo are visit proposals: download instead of visiting.
final class DownloadRouteDecisionHandler: RouteDecisionHandler {
    let name = "download"

    #if HOTWIRE_LEGACY
    func matches(location: URL, configuration: Navigator.Configuration) -> Bool {
        Downloads.isDownload(location)
    }

    func handle(location: URL, configuration: Navigator.Configuration, navigator: Navigator) -> Router.Decision {
        Task { @MainActor in Downloads.download(location, from: navigator.rootViewController) }
        return .cancel
    }
    #else
    func matches(proposal: VisitProposal, configuration: Navigator.Configuration) -> Bool {
        Downloads.isDownload(proposal.url)
    }

    func handle(proposal: VisitProposal, configuration: Navigator.Configuration, navigator: Navigating) -> Router.Decision {
        let url = proposal.url
        let presenter = navigator.rootViewController
        Task { @MainActor in Downloads.download(url, from: presenter) }
        return .cancel
    }
    #endif
}
