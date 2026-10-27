import SafariServices
import UIKit

/// Native login + 2FA (spec §3.2 step 3): POST /api/v1/app/auth/login; on
/// `401 two_factor_required` the code field appears and the form is resent with `otp`.
final class LoginViewController: UIViewController, UITextFieldDelegate {
    private let target: RouteTarget?
    private let notice: String?

    private let username = UITextField()
    private let password = UITextField()
    private let otp = UITextField()
    private let errorLabel = UILabel()
    private let submit = UIButton(configuration: .filled())

    init(target: RouteTarget?, notice: String?) {
        self.target = target
        self.notice = notice
        super.init(nibName: nil, bundle: nil)
    }

    @available(*, unavailable) required init?(coder: NSCoder) { fatalError() }

    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = .systemBackground
        navigationItem.largeTitleDisplayMode = .always
        navigationController?.navigationBar.prefersLargeTitles = true
        title = L("login_title")
        buildUI()
        if let notice { showError(notice) }
    }

    private func buildUI() {
        let subtitle = UILabel()
        subtitle.text = L("login_subtitle")
        subtitle.textColor = .secondaryLabel
        subtitle.numberOfLines = 0

        configure(username, placeholder: L("login_username"), content: .username)
        username.keyboardType = .emailAddress
        username.returnKeyType = .next
        configure(password, placeholder: L("login_password"), content: .password)
        password.isSecureTextEntry = true
        password.returnKeyType = .go
        configure(otp, placeholder: L("login_otp"), content: .oneTimeCode)
        otp.keyboardType = .asciiCapable
        otp.returnKeyType = .go
        otp.isHidden = true

        errorLabel.textColor = .systemRed
        errorLabel.numberOfLines = 0
        errorLabel.isHidden = true
        errorLabel.accessibilityTraits = .updatesFrequently

        submit.setTitle(L("login_submit"), for: .normal)
        submit.addAction(UIAction { [weak self] _ in self?.submitForm() }, for: .touchUpInside)

        let forgot = UIButton(configuration: .plain())
        forgot.setTitle(L("login_forgot"), for: .normal)
        forgot.addAction(UIAction { [weak self] _ in self?.openInBrowser("/password/reset") }, for: .touchUpInside)
        let register = UIButton(configuration: .plain())
        register.setTitle(L("login_register"), for: .normal)
        register.addAction(UIAction { [weak self] _ in self?.openInBrowser("/signup") }, for: .touchUpInside)

        let stack = UIStackView(arrangedSubviews: [subtitle, username, password, otp, errorLabel, submit, forgot, register])
        stack.axis = .vertical
        stack.spacing = 14
        stack.setCustomSpacing(24, after: subtitle)
        stack.setCustomSpacing(24, after: errorLabel)
        stack.translatesAutoresizingMaskIntoConstraints = false

        let scroll = UIScrollView()
        scroll.keyboardDismissMode = .interactive
        scroll.translatesAutoresizingMaskIntoConstraints = false
        view.addSubview(scroll)
        scroll.addSubview(stack)
        NSLayoutConstraint.activate([
            scroll.topAnchor.constraint(equalTo: view.topAnchor),
            scroll.bottomAnchor.constraint(equalTo: view.keyboardLayoutGuide.topAnchor),
            scroll.leadingAnchor.constraint(equalTo: view.leadingAnchor),
            scroll.trailingAnchor.constraint(equalTo: view.trailingAnchor),
            stack.topAnchor.constraint(equalTo: scroll.contentLayoutGuide.topAnchor, constant: 16),
            stack.bottomAnchor.constraint(equalTo: scroll.contentLayoutGuide.bottomAnchor, constant: -24),
            stack.leadingAnchor.constraint(equalTo: scroll.frameLayoutGuide.leadingAnchor, constant: 24),
            stack.trailingAnchor.constraint(equalTo: scroll.frameLayoutGuide.trailingAnchor, constant: -24),
            submit.heightAnchor.constraint(greaterThanOrEqualToConstant: 52),
        ])
    }

    private func configure(_ field: UITextField, placeholder: String, content: UITextContentType) {
        field.placeholder = placeholder
        field.accessibilityLabel = placeholder
        field.textContentType = content
        field.borderStyle = .roundedRect
        field.autocapitalizationType = .none
        field.autocorrectionType = .no
        field.delegate = self
        field.heightAnchor.constraint(greaterThanOrEqualToConstant: 48).isActive = true
    }

    func textFieldShouldReturn(_ textField: UITextField) -> Bool {
        if textField == username { password.becomeFirstResponder() } else { submitForm() }
        return true
    }

    private func submitForm() {
        let user = (username.text ?? "").trimmingCharacters(in: .whitespaces)
        let pass = password.text ?? ""
        guard !user.isEmpty, !pass.isEmpty else { return showError(L("login_missing")) }
        setBusy(true)
        Task { @MainActor in
            do {
                let res = try await OwnerAPI.shared.login(username: user, password: pass, otp: otp.isHidden ? nil : otp.text)
                guard SecureStore.shared.writeToken(res.token) else {
                    setBusy(false)
                    return showError(L("error_title"))
                }
                if SecureStore.shared.storeID == nil { SecureStore.shared.storeID = res.defaultStoreId }
                LauncherViewController.unlocked = true // the owner just proved who they are
                SceneDelegate.current?.showLauncher(mode: .relogin, target: target)
            } catch let error as APIError {
                setBusy(false)
                if error.needsTwoFactor {
                    otp.isHidden = false
                    otp.becomeFirstResponder()
                    showError(L("login_two_factor"))
                } else {
                    showError(error.message)
                }
            } catch {
                setBusy(false)
                showError(L("offline_body"))
            }
        }
    }

    private func setBusy(_ busy: Bool) {
        submit.isEnabled = !busy
        submit.setTitle(L(busy ? "login_busy" : "login_submit"), for: .normal)
        if busy { errorLabel.isHidden = true }
    }

    private func showError(_ text: String) {
        errorLabel.text = text
        errorLabel.isHidden = false
        UIAccessibility.post(notification: .announcement, argument: text)
    }

    /// Sign up / forgot password → in-app browser (spec §3.2 step 3).
    private func openInBrowser(_ path: String) {
        present(SFSafariViewController(url: AppConfig.url(path)), animated: true)
    }
}
