package com.juwri.owner.auth

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.View
import android.webkit.CookieManager
import android.webkit.WebStorage
import android.widget.Button
import android.widget.ProgressBar
import android.widget.TextView
import androidx.activity.enableEdgeToEdge
import androidx.appcompat.app.AppCompatActivity
import androidx.appcompat.app.AppCompatDelegate
import androidx.core.os.LocaleListCompat
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricManager.Authenticators.BIOMETRIC_STRONG
import androidx.biometric.BiometricManager.Authenticators.DEVICE_CREDENTIAL
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.juwri.owner.AppConfig
import com.juwri.owner.R
import com.juwri.owner.api.ApiException
import com.juwri.owner.api.ApiJson
import com.juwri.owner.api.MeResponse
import com.juwri.owner.api.OwnerApi
import com.juwri.owner.shell.MainActivity
import com.juwri.owner.util.applySystemBarInsets
import kotlinx.coroutines.launch
import java.io.IOException

/**
 * Native launch / version gate / offline screen (spec §3.2, §3.8):
 * config → (update screen) → token? → biometric → /me → /session hand-off → tabs.
 * Also runs the session-lost re-entry and the web-logout wipe for [LoginFragment].
 */
class LauncherActivity : AppCompatActivity() {
    private val api by lazy { OwnerApi(this) }
    private val store by lazy { SecureStore.get(this) }

    private lateinit var progress: ProgressBar
    private lateinit var title: TextView
    private lateinit var message: TextView
    private lateinit var action: Button

    private var target: RouteTarget? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_launcher)
        findViewById<View>(R.id.root).applySystemBarInsets()
        progress = findViewById(R.id.progress)
        title = findViewById(R.id.title)
        message = findViewById(R.id.message)
        action = findViewById(R.id.action)
        handle(intent)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handle(intent)
    }

    private fun handle(intent: Intent) {
        target = RouteTarget.from(intent) ?: target
        when (intent.getStringExtra(EXTRA_MODE)) {
            MODE_LOGOUT -> logoutThenLogin()
            else -> start()
        }
    }

    private fun start() {
        showLoading()
        lifecycleScope.launch {
            try {
                val config = api.config()
                val version = config.versions[AppConfig.PLATFORM]
                if (!config.enabled) {
                    showState(getString(R.string.app_disabled_title), getString(R.string.app_disabled_body), null, null)
                    return@launch
                }
                if (version?.updateRequired == true) {
                    showUpdateRequired(version.storeUrl)
                    return@launch
                }
                updateAvailable = version?.updateAvailable == true

                if (!store.hasToken()) {
                    openLogin()
                    return@launch
                }
                if (!unlocked && biometricAvailable()) {
                    promptBiometric()
                    return@launch
                }
                unlocked = true
                enterDashboard()
            } catch (e: IOException) {
                if (com.juwri.owner.BuildConfig.DEBUG) android.util.Log.d("OwnerLauncher", "offline: ${e.javaClass.simpleName}: ${e.message}")
                showOffline()
            } catch (e: ApiException) {
                showState(getString(R.string.error_title), e.message, getString(R.string.retry)) { start() }
            }
        }
    }

    private suspend fun enterDashboard() {
        try {
            val me = api.me(store.storeId)
            if (applyOwnerLocale(me.user.locale)) return // activity recreates in the owner's language and re-runs start()
            val storeId = me.store?.id ?: me.defaultStoreId
            store.storeId = storeId

            val firstPath = me.tabs.firstOrNull { it.path != null }?.path ?: me.onboardingPath ?: "/dashboard"
            val session = api.session(to = firstPath, storeId = storeId)
            val landing = api.handoff(session.url)
            if (landing == null) {
                // The server refused the hand-off (token revoked mid-flight) → sign in again.
                signOutLocally()
                openLogin()
                return
            }

            startActivity(MainActivity.intent(this, ApiJson.encodeToString(MeResponse.serializer(), me), landing, target, updateAvailable))
            target = null
            overridePendingTransition(0, 0)
            finish()
        } catch (e: ApiException) {
            if (e.status == 401 || e.code in setOf("account_blocked", "admin_not_supported", "app_token_required")) {
                // Token dead / revoked on the dashboard → native login (spec A1 DONE check).
                signOutLocally()
                openLogin(e.message.takeIf { e.status == 403 })
            } else {
                showState(getString(R.string.error_title), e.message, getString(R.string.retry)) { start() }
            }
        } catch (e: IOException) {
            showOffline()
        }
    }

    /**
     * Native chrome (toolbars, sheets, dialogs, strings) follows the OWNER's dashboard
     * language, not the phone's — so an Arabic owner gets a mirrored shell (spec §3.5).
     * Returns true when the locale changed (AppCompat recreates this activity).
     */
    private fun applyOwnerLocale(locale: String?): Boolean {
        val tag = locale?.takeIf { it.isNotBlank() } ?: return false
        if (AppCompatDelegate.getApplicationLocales().toLanguageTags() == tag) return false
        AppCompatDelegate.setApplicationLocales(LocaleListCompat.forLanguageTags(tag))
        return true
    }

    private fun logoutThenLogin() {
        showLoading()
        lifecycleScope.launch {
            // Web logout landed on /login?app_logout=1 (spec §3.2 step 8).
            runCatching { api.logout(store.registeredPushToken) }
            signOutLocally()
            openLogin()
        }
    }

    private fun signOutLocally() {
        store.wipe()
        unlocked = false
        clearWebData()
    }

    private fun openLogin(notice: String? = null) {
        startActivity(Intent(this, LoginActivity::class.java).apply {
            putExtra(RouteTarget.EXTRA, target)
            putExtra(LoginActivity.EXTRA_NOTICE, notice)
        })
        overridePendingTransition(0, 0)
        finish()
    }

    // ── biometric gate (spec §3.2 step 2) ────────────────────────────────────

    private fun biometricAvailable(): Boolean =
        BiometricManager.from(this).canAuthenticate(BIOMETRIC_STRONG or DEVICE_CREDENTIAL) == BiometricManager.BIOMETRIC_SUCCESS

    private fun promptBiometric() {
        showState(getString(R.string.unlock_title), getString(R.string.unlock_body), getString(R.string.unlock_action)) { promptBiometric() }
        val prompt = BiometricPrompt(this, ContextCompat.getMainExecutor(this), object : BiometricPrompt.AuthenticationCallback() {
            override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                unlocked = true
                start()
            }
        })
        prompt.authenticate(
            BiometricPrompt.PromptInfo.Builder()
                .setTitle(getString(R.string.unlock_title))
                .setSubtitle(getString(R.string.app_name))
                .setAllowedAuthenticators(BIOMETRIC_STRONG or DEVICE_CREDENTIAL)
                .build()
        )
    }

    // ── states ───────────────────────────────────────────────────────────────

    private fun showLoading() {
        progress.visibility = View.VISIBLE
        title.visibility = View.GONE
        message.visibility = View.GONE
        action.visibility = View.GONE
    }

    private fun showOffline() = showState(
        getString(R.string.offline_title), getString(R.string.offline_body), getString(R.string.retry)
    ) { start() }

    private fun showUpdateRequired(storeUrl: String) = showState(
        getString(R.string.update_title), getString(R.string.update_body),
        if (storeUrl.isNotBlank()) getString(R.string.update_action) else null,
    ) { startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(storeUrl))) }

    private fun showState(t: String, m: String?, button: String?, onClick: (() -> Unit)?) {
        progress.visibility = View.GONE
        title.visibility = View.VISIBLE
        title.text = t
        message.visibility = if (m.isNullOrBlank()) View.GONE else View.VISIBLE
        message.text = m
        action.visibility = if (button != null) View.VISIBLE else View.GONE
        action.text = button
        action.setOnClickListener { onClick?.invoke() }
    }

    companion object {
        const val EXTRA_MODE = "launcher_mode"
        const val MODE_RELOGIN = "relogin"
        const val MODE_LOGOUT = "logout"

        /** Biometric unlock is per process: store switches and re-entries don't re-prompt. */
        @Volatile var unlocked = false
        @Volatile var updateAvailable = false

        fun restart(context: Context, mode: String? = null, target: RouteTarget? = null) {
            context.startActivity(Intent(context, LauncherActivity::class.java).apply {
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK)
                putExtra(EXTRA_MODE, mode)
                putExtra(RouteTarget.EXTRA, target)
            })
        }

        /** Wipes the ONE cookie store all web views share, plus web storage (rule 7). */
        fun clearWebData() {
            CookieManager.getInstance().removeAllCookies(null)
            CookieManager.getInstance().flush()
            WebStorage.getInstance().deleteAllData()
        }
    }
}
