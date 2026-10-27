import HotwireNative
import UIKit

/// Every dashboard screen (default destination).
final class WebViewController: HotwireWebViewController {}

/// Native offline / error screen with Retry (spec §3.8; App Review tests Airplane Mode).
final class OfflineView: UIView {
    private var retry: (() -> Void)?

    static func present(on vc: UIViewController, error: Error, retry: @escaping () -> Void) {
        vc.view.subviews.compactMap { $0 as? OfflineView }.forEach { $0.removeFromSuperview() }
        let view = OfflineView(error: error, retry: retry)
        view.translatesAutoresizingMaskIntoConstraints = false
        vc.view.addSubview(view)
        NSLayoutConstraint.activate([
            view.topAnchor.constraint(equalTo: vc.view.topAnchor),
            view.bottomAnchor.constraint(equalTo: vc.view.bottomAnchor),
            view.leadingAnchor.constraint(equalTo: vc.view.leadingAnchor),
            view.trailingAnchor.constraint(equalTo: vc.view.trailingAnchor),
        ])
    }

    private init(error: Error, retry: @escaping () -> Void) {
        self.retry = retry
        super.init(frame: .zero)
        backgroundColor = .systemBackground

        let offline = (error as? URLError) != nil || String(describing: error).lowercased().contains("offline")
            || String(describing: error).contains("notConnectedToInternet") || String(describing: error).contains("network")
        let icon = UIImageView(image: UIImage(systemName: offline ? "wifi.slash" : "exclamationmark.triangle"))
        icon.tintColor = .secondaryLabel
        icon.contentMode = .scaleAspectFit
        let title = UILabel()
        title.text = L(offline ? "offline_title" : "error_title")
        title.font = .preferredFont(forTextStyle: .title2)
        title.textAlignment = .center
        let message = UILabel()
        message.text = offline ? L("offline_body") : error.localizedDescription
        message.textColor = .secondaryLabel
        message.numberOfLines = 0
        message.textAlignment = .center
        let button = UIButton(configuration: .filled())
        button.setTitle(L("retry"), for: .normal)
        button.addAction(UIAction { [weak self] _ in
            self?.removeFromSuperview()
            self?.retry?()
        }, for: .touchUpInside)

        let stack = UIStackView(arrangedSubviews: [icon, title, message, button])
        stack.axis = .vertical
        stack.alignment = .center
        stack.spacing = 12
        stack.translatesAutoresizingMaskIntoConstraints = false
        addSubview(stack)
        NSLayoutConstraint.activate([
            icon.widthAnchor.constraint(equalToConstant: 48), icon.heightAnchor.constraint(equalToConstant: 48),
            button.heightAnchor.constraint(greaterThanOrEqualToConstant: 50),
            stack.centerYAnchor.constraint(equalTo: centerYAnchor),
            stack.leadingAnchor.constraint(equalTo: layoutMarginsGuide.leadingAnchor, constant: 24),
            stack.trailingAnchor.constraint(equalTo: layoutMarginsGuide.trailingAnchor, constant: -24),
        ])
    }

    @available(*, unavailable) required init?(coder: NSCoder) { fatalError() }
}

/// Semantic `icon` keys from /me (and bridge buttons) → SF Symbols.
enum Icons {
    static func image(for key: String?) -> UIImage? {
        let name: String
        switch key?.lowercased() {
        case "home", "house": name = "house"
        case "receipt", "orders", "order", "board", "clipboard": name = "list.bullet.rectangle"
        case "book-open", "book", "catalog": name = "book"
        case "package", "box": name = "shippingbox"
        case "bed", "rooms": name = "bed.double"
        case "monitor", "kds": name = "display"
        case "star", "reviews": name = "star"
        case "bell", "notifications": name = "bell"
        case "smartphone", "mobile": name = "iphone"
        case "chart", "reports", "bar-chart": name = "chart.bar"
        case "calendar", "reservations", "bookings": name = "calendar"
        case "menu", "more", "grid": name = "ellipsis.circle"
        case "store", "shop": name = "storefront"
        case "settings", "cog", "gear": name = "gearshape"
        case "users", "people", "customers": name = "person.2"
        case "user", "account": name = "person.crop.circle"
        default: name = "circle"
        }
        return UIImage(systemName: name)
    }
}
