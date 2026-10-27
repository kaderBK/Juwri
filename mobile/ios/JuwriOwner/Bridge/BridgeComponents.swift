import HotwireNative
import UIKit

/// Nav-bar slots shared by `form` and `button` (BRIDGE.md §3.4): two separate items,
/// the form's Save rightmost; one never overwrites the other.
private enum NavBarSlot: Int {
    case form = 0x4D42_0001
    case button = 0x4D42_0002

    static func set(_ item: UIBarButtonItem?, slot: NavBarSlot, on vc: UIViewController?) {
        guard let vc else { return }
        var items = (vc.navigationItem.rightBarButtonItems ?? []).filter { $0.tag != slot.rawValue }
        if let item {
            item.tag = slot.rawValue
            items.append(item)
        }
        // rightBarButtonItems[0] is the rightmost → form first, then button.
        items.sort { a, b in a.tag == NavBarSlot.form.rawValue && b.tag != NavBarSlot.form.rawValue }
        vc.navigationItem.rightBarButtonItems = items
    }
}

/// `form` (BRIDGE.md §3.1): native Save. connect {submitTitle} → show/replace + enable;
/// tap → reply to connect (the web clicks its submit target); submitDisabled/Enabled.
final class FormComponent: BridgeComponent {
    override class var name: String { "form" }

    private weak var item: UIBarButtonItem?
    private var viewController: UIViewController? { delegate?.destination as? UIViewController }

    override func onReceive(message: Message) {
        switch message.event {
        case "connect":
            guard let data: ConnectData = message.data() else { return }
            let item = UIBarButtonItem(title: data.submitTitle, primaryAction: UIAction { [weak self] _ in
                self?.reply(to: "connect")
            })
            item.style = .done
            item.accessibilityLabel = data.submitTitle
            NavBarSlot.set(item, slot: .form, on: viewController)
            self.item = item
        case "submitEnabled":
            item?.isEnabled = true
        case "submitDisabled":
            item?.isEnabled = false
        default:
            break // unknown events are ignored (the web may add more)
        }
    }

    private struct ConnectData: Decodable { let submitTitle: String }
}

/// `button` (BRIDGE.md §3.3): a nav-bar action. connect {title, iosImage, androidImage}
/// → the SF Symbol when present (title = accessibility label), else the title;
/// tap → reply to connect (the web clicks the element).
final class ButtonComponent: BridgeComponent {
    override class var name: String { "button" }

    private var viewController: UIViewController? { delegate?.destination as? UIViewController }

    override func onReceive(message: Message) {
        guard message.event == "connect", let data: ConnectData = message.data() else { return }
        let action = UIAction { [weak self] _ in self?.reply(to: "connect") }
        let item: UIBarButtonItem
        if let name = data.iosImage, let image = UIImage(systemName: name) {
            item = UIBarButtonItem(image: image, primaryAction: action)
        } else {
            item = UIBarButtonItem(title: data.title, primaryAction: action)
        }
        item.accessibilityLabel = data.title
        NavBarSlot.set(item, slot: .button, on: viewController)
    }

    private struct ConnectData: Decodable {
        let title: String
        let iosImage: String?
        let androidImage: String?
    }
}

/// `menu` (BRIDGE.md §3.2): display {title, items[{title, index, destructive}], source}
/// → action sheet with Cancel; a pick replies {selectedIndex: item.index}. Cancel sends nothing.
final class MenuComponent: BridgeComponent {
    override class var name: String { "menu" }

    private var viewController: UIViewController? { delegate?.destination as? UIViewController }

    override func onReceive(message: Message) {
        guard message.event == "display", let data: DisplayData = message.data() else { return }

        let sheet = UIAlertController(title: data.title.isEmpty ? nil : data.title, message: nil, preferredStyle: .actionSheet)
        for item in data.items {
            sheet.addAction(UIAlertAction(title: item.title, style: item.destructive == true ? .destructive : .default) { [weak self] _ in
                self?.reply(to: "display", with: Selection(selectedIndex: item.index))
            })
        }
        sheet.addAction(UIAlertAction(title: L("cancel"), style: .cancel))

        // iPad / iOS 26 popover anchor: `source` is CSS px relative to the visible web content.
        if let popover = sheet.popoverPresentationController, let vc = viewController {
            popover.sourceView = vc.view
            if let s = data.source, let web = (vc as? Visitable)?.visitableView.webView {
                let top = web.scrollView.adjustedContentInset.top
                popover.sourceRect = CGRect(x: s.x, y: s.y + Double(top), width: s.width, height: s.height)
            } else {
                popover.sourceRect = CGRect(x: vc.view.bounds.midX, y: vc.view.bounds.midY, width: 1, height: 1)
            }
        }
        viewController?.present(sheet, animated: true)
    }

    private struct Source: Decodable { let x: Double; let y: Double; let width: Double; let height: Double }
    private struct Item: Decodable { let title: String; let index: Int; let destructive: Bool? }
    private struct DisplayData: Decodable {
        let title: String
        let items: [Item]
        let source: Source?
    }
    private struct Selection: Encodable { let selectedIndex: Int }
}
