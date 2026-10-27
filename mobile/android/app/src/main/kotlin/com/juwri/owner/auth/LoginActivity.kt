package com.juwri.owner.auth

import android.os.Bundle
import android.view.View
import android.view.inputmethod.EditorInfo
import android.widget.Button
import android.widget.TextView
import androidx.activity.enableEdgeToEdge
import androidx.appcompat.app.AppCompatActivity
import androidx.browser.customtabs.CustomTabsIntent
import androidx.core.net.toUri
import androidx.lifecycle.lifecycleScope
import com.google.android.material.textfield.TextInputEditText
import com.google.android.material.textfield.TextInputLayout
import com.juwri.owner.AppConfig
import com.juwri.owner.R
import com.juwri.owner.api.ApiException
import com.juwri.owner.api.OwnerApi
import com.juwri.owner.util.applySystemBarInsets
import kotlinx.coroutines.launch
import java.io.IOException

/**
 * Native login + 2FA (spec §3.2 step 3): POST /api/v1/app/auth/login; on
 * `401 two_factor_required` the code field appears and the form is resent with
 * `otp` (TOTP or recovery code). The token goes straight into [SecureStore].
 */
class LoginActivity : AppCompatActivity() {
    private val api by lazy { OwnerApi(this) }
    private val store by lazy { SecureStore.get(this) }

    private lateinit var username: TextInputEditText
    private lateinit var password: TextInputEditText
    private lateinit var otpLayout: TextInputLayout
    private lateinit var otp: TextInputEditText
    private lateinit var error: TextView
    private lateinit var submit: Button

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_login)
        findViewById<View>(R.id.root).applySystemBarInsets()

        username = findViewById(R.id.username)
        password = findViewById(R.id.password)
        otpLayout = findViewById(R.id.otp_layout)
        otp = findViewById(R.id.otp)
        error = findViewById(R.id.error)
        submit = findViewById(R.id.submit)

        intent.getStringExtra(EXTRA_NOTICE)?.let { showError(it) }

        submit.setOnClickListener { submit() }
        otp.setOnEditorActionListener { _, id, _ -> (id == EditorInfo.IME_ACTION_DONE).also { if (it) submit() } }
        password.setOnEditorActionListener { _, id, _ ->
            (id == EditorInfo.IME_ACTION_DONE && otpLayout.visibility != View.VISIBLE).also { if (it) submit() }
        }
        findViewById<View>(R.id.forgot).setOnClickListener { openInBrowser("/password/reset") }
        findViewById<View>(R.id.register).setOnClickListener { openInBrowser("/signup") }
    }

    private fun submit() {
        val user = username.text?.toString()?.trim().orEmpty()
        val pass = password.text?.toString().orEmpty()
        if (user.isEmpty() || pass.isEmpty()) {
            showError(getString(R.string.login_missing))
            return
        }
        setBusy(true)
        lifecycleScope.launch {
            try {
                val res = api.login(user, pass, otp.text?.toString().takeIf { otpLayout.visibility == View.VISIBLE })
                store.writeToken(res.token)
                if (store.storeId == null) store.storeId = res.defaultStoreId
                LauncherActivity.unlocked = true // the owner just proved who they are
                @Suppress("DEPRECATION")
                LauncherActivity.restart(this@LoginActivity, LauncherActivity.MODE_RELOGIN, intent.getParcelableExtra(RouteTarget.EXTRA))
                finish()
            } catch (e: ApiException) {
                setBusy(false)
                if (e.needsTwoFactor) {
                    otpLayout.visibility = View.VISIBLE
                    otp.requestFocus()
                    showError(getString(R.string.login_two_factor))
                } else {
                    showError(e.message)
                }
            } catch (e: IOException) {
                setBusy(false)
                showError(getString(R.string.offline_body))
            }
        }
    }

    private fun setBusy(busy: Boolean) {
        submit.isEnabled = !busy
        submit.text = getString(if (busy) R.string.login_busy else R.string.login_submit)
        if (busy) error.visibility = View.GONE
    }

    private fun showError(text: String) {
        error.text = text
        error.visibility = View.VISIBLE
    }

    /** Sign up / forgot password → in-app browser (spec §3.2 step 3). */
    private fun openInBrowser(path: String) {
        CustomTabsIntent.Builder().setShowTitle(true).build().launchUrl(this, AppConfig.url(path).toUri())
    }

    companion object {
        const val EXTRA_NOTICE = "notice"
    }
}
