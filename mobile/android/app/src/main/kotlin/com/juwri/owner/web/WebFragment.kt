package com.juwri.owner.web

import android.annotation.SuppressLint
import android.view.View
import android.widget.TextView
import com.juwri.owner.R
import dev.hotwire.core.turbo.errors.HttpError
import dev.hotwire.core.turbo.errors.VisitError
import dev.hotwire.core.turbo.errors.WebError
import dev.hotwire.core.turbo.webview.HotwireWebView
import dev.hotwire.navigation.destinations.HotwireDestination
import dev.hotwire.navigation.destinations.HotwireDestinationDeepLink
import dev.hotwire.navigation.fragments.HotwireWebBottomSheetFragment
import dev.hotwire.navigation.fragments.HotwireWebFragment

/** Every dashboard screen: a native screen around the live web page. */
@HotwireDestinationDeepLink(uri = "hotwire://fragment/web")
open class WebFragment : HotwireWebFragment() {
    override fun createErrorView(error: VisitError): View = errorView(this, error) { refresh(displayProgress = true) }

    override fun onWebViewAttached(webView: HotwireWebView) {
        super.onWebViewAttached(webView)
        Downloads.attach(webView)
    }

    override fun onVisitCompleted(location: String, completedOffline: Boolean) {
        super.onVisitCompleted(location, completedOffline)
        LoginGuard.check(this)
    }

    override fun onColdBootPageCompleted(location: String) {
        super.onColdBootPageCompleted(location)
        LoginGuard.check(this)
    }
}

/**
 * A visit whose response was REDIRECTED to /login (session expired) is not a visit
 * proposal, so the `^/login` path rule never sees it and the web login page would
 * render inside the app. Check where the page actually ended up and hand over to
 * the native re-entry (spec §3.2 step 7) / logout (step 8).
 */
internal object LoginGuard {
    private val loginPath = Regex("^(/[a-z]{2})?/login/?$")

    fun check(destination: HotwireDestination) {
        val fragment = destination.fragment
        val webView = destination.navigator.session.webView
        webView.evaluateJavascript("location.pathname + '|' + location.search") { raw ->
            val value = raw?.trim('"') ?: return@evaluateJavascript
            val path = value.substringBefore('|')
            if (!loginPath.matches(path) || !fragment.isAdded) return@evaluateJavascript
            val loggedOut = value.substringAfter('|').contains("app_logout=1")
            com.juwri.owner.auth.LauncherActivity.restart(
                fragment.requireContext(),
                if (loggedOut) com.juwri.owner.auth.LauncherActivity.MODE_LOGOUT else com.juwri.owner.auth.LauncherActivity.MODE_RELOGIN,
            )
            fragment.requireActivity().finish()
        }
    }
}

/** `/new`, `/edit`, `/create` forms (path configuration `context: modal`). */
@HotwireDestinationDeepLink(uri = "hotwire://fragment/web/modal/sheet")
class WebBottomSheetFragment : HotwireWebBottomSheetFragment() {
    override fun createErrorView(error: VisitError): View = errorView(this, error) { refresh(displayProgress = true) }

    override fun onWebViewAttached(webView: HotwireWebView) {
        super.onWebViewAttached(webView)
        Downloads.attach(webView)
    }

    override fun onVisitCompleted(location: String, completedOffline: Boolean) {
        super.onVisitCompleted(location, completedOffline)
        LoginGuard.check(this)
    }
}

/** Native offline / error screen with Retry (spec §3.8; App Review tests Airplane Mode). */
@SuppressLint("InflateParams")
internal fun errorView(fragment: androidx.fragment.app.Fragment, error: VisitError, retry: () -> Unit): View {
    val view = fragment.layoutInflater.inflate(R.layout.view_error, null)
    val offline = error is WebError || (error !is HttpError)
    view.findViewById<TextView>(R.id.error_title).setText(if (offline) R.string.offline_title else R.string.error_title)
    view.findViewById<TextView>(R.id.error_message).text =
        if (offline) fragment.getString(R.string.offline_body) else error.description()
    view.findViewById<View>(R.id.error_retry).setOnClickListener { retry() }
    return view
}
