package com.juwri.owner.web

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.net.toUri
import com.juwri.owner.R
import com.juwri.owner.auth.LauncherActivity
import dev.hotwire.navigation.destinations.HotwireDestinationDeepLink
import dev.hotwire.navigation.fragments.HotwireFragment

/**
 * Path-configuration rule `^/login` → here (spec §3.2 steps 7–8, §3.4).
 *  - `/login?app_logout=1` → the owner logged out on the web: revoke the app token,
 *    wipe token + cookies, show the native login.
 *  - any other `/login` → the web session was lost: re-enter silently with the stored
 *    token (fresh /session hand-off); a dead token ends on the native login form.
 * Both run in [LauncherActivity], which replaces the whole tab stack (clear_all).
 */
@HotwireDestinationDeepLink(uri = "hotwire://fragment/login")
class LoginFragment : HotwireFragment() {
    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View =
        inflater.inflate(R.layout.fragment_login_gate, container, false)

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        if (savedInstanceState != null) return
        val loggedOut = location.toUri().getQueryParameter("app_logout") == "1"
        LauncherActivity.restart(
            requireContext(),
            if (loggedOut) LauncherActivity.MODE_LOGOUT else LauncherActivity.MODE_RELOGIN,
        )
        requireActivity().finish()
    }
}
