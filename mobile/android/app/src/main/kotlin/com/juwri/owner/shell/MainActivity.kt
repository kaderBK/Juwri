package com.juwri.owner.shell

import android.content.Context
import android.content.Intent
import android.os.Bundle
import android.view.View
import android.view.ViewGroup
import android.widget.TextView
import androidx.activity.enableEdgeToEdge
import androidx.core.view.isVisible
import com.google.android.material.bottomnavigation.BottomNavigationView
import com.google.android.material.bottomsheet.BottomSheetDialog
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import com.juwri.owner.AppConfig
import com.juwri.owner.R
import com.juwri.owner.api.ApiJson
import com.juwri.owner.api.MeResponse
import com.juwri.owner.api.Tab
import com.juwri.owner.auth.LauncherActivity
import com.juwri.owner.auth.RouteTarget
import com.juwri.owner.auth.SecureStore
import com.juwri.owner.push.PushRegistrar
import dev.hotwire.navigation.activities.HotwireActivity
import dev.hotwire.navigation.navigator.Navigator
import dev.hotwire.navigation.navigator.NavigatorConfiguration
import dev.hotwire.navigation.tabs.HotwireBottomNavigationController
import dev.hotwire.navigation.tabs.HotwireBottomTab
import dev.hotwire.navigation.util.applyDefaultImeWindowInsets

/**
 * The shell (spec §3.5): up to four web tabs from `/me` — one Navigator (and one
 * web view) per tab — plus a native "More" sheet built from `/me.more` with the
 * store picker. Tabs are rebuilt by relaunching through [LauncherActivity] with a
 * new store (fresh /me, fresh session, push re-registered).
 */
class MainActivity : HotwireActivity() {
    private lateinit var me: MeResponse
    private lateinit var tabs: List<Pair<Tab, HotwireBottomTab>>
    private lateinit var bottomNav: BottomNavigationView
    private lateinit var tabController: HotwireBottomNavigationController
    private var pending: RouteTarget? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        // Tabs must exist before super.onCreate(): the Hotwire delegate reads them there.
        me = ApiJson.decodeFromString(MeResponse.serializer(), requireNotNull(intent.getStringExtra(EXTRA_ME)))
        tabs = buildTabs(me, intent.getStringExtra(EXTRA_LANDING))
        @Suppress("DEPRECATION")
        pending = if (savedInstanceState == null) intent.getParcelableExtra(RouteTarget.EXTRA) else null

        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)
        val root = findViewById<View>(R.id.root)
        root.applyDefaultImeWindowInsets()
        // RTL mirroring for Arabic / Urdu owners (spec §3.5), independent of the device locale.
        root.layoutDirection = if (me.user.dir == "rtl") View.LAYOUT_DIRECTION_RTL else View.LAYOUT_DIRECTION_LTR

        initTabs(savedInstanceState?.getInt(STATE_TAB) ?: 0)

        if (intent.getBooleanExtra(EXTRA_UPDATE_AVAILABLE, false) && savedInstanceState == null) {
            Snackbar.make(root, R.string.update_available, Snackbar.LENGTH_LONG).setAnchorView(bottomNav).show()
        }

        PushRegistrar.onShellReady(this, me.store?.id ?: me.defaultStoreId, me.store?.pushEnabled == true)
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        outState.putInt(STATE_TAB, bottomNav.selectedItemId.coerceAtLeast(0))
    }

    override fun navigatorConfigurations(): List<NavigatorConfiguration> = tabs.map { it.second.configuration }

    override fun onNavigatorReady(navigator: Navigator) {
        // hideTabBarWhenPushed (spec §3.5): pushed screens get the full height.
        navigator.host.navController.addOnDestinationChangedListener { _, _, _ ->
            bottomNav.post { updateTabBarVisibility(bottomNav.selectedItemId) }
        }
        val target = pending ?: return
        pending = null
        // Ready = the start destination is attached, not yet created: route on the next frame.
        bottomNav.post { route(target) }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        RouteTarget.from(intent)?.let { route(it) }
    }

    /** Push taps / app links (spec §3.6): data.url → there; else a tab (orders / home). */
    fun route(target: RouteTarget) {
        val url = target.url
        if (url != null) {
            delegate.currentNavigator?.route(url) ?: run { pending = target }
            return
        }
        val index = tabs.indexOfFirst { it.first.key == target.tabKey }
        if (index >= 0) bottomNav.selectedItemId = index
    }

    // ── tabs ─────────────────────────────────────────────────────────────────

    private fun buildTabs(me: MeResponse, landing: String?): List<Pair<Tab, HotwireBottomTab>> {
        val web = me.tabs.filter { it.path != null }.take(HOST_IDS.size).ifEmpty {
            // No store yet → onboarding (spec §4 /me: tabs: [], onboarding_path).
            listOf(Tab(key = "home", label = getString(R.string.tab_home), icon = "home", path = me.onboardingPath ?: "/dashboard"))
        }
        return web.mapIndexed { i, tab ->
            val start = if (i == 0 && landing != null) landing else AppConfig.url(tab.path!!)
            tab to HotwireBottomTab(
                title = tab.label,
                iconResId = Icons.forKey(tab.icon),
                configuration = NavigatorConfiguration(name = "tab-${tab.key}", startLocation = start, navigatorHostId = HOST_IDS[i]),
            )
        }
    }

    private fun initTabs(selected: Int) {
        bottomNav = findViewById(R.id.bottom_nav)
        HOST_IDS.drop(tabs.size).forEach { findViewById<View>(it).isVisible = false }

        tabController = HotwireBottomNavigationController(activity = this, view = bottomNav, lazyLoadTabs = true)
        tabController.load(tabs.map { it.second }, selected.coerceIn(0, tabs.lastIndex))

        val hasMore = true // always: holds Log out
        if (hasMore) {
            val moreTab = me.tabs.firstOrNull { it.path == null }
            bottomNav.menu.add(0, MORE_ID, tabs.size, moreTab?.label ?: getString(R.string.tab_more))
                .setIcon(Icons.forKey(moreTab?.icon ?: "menu"))
        }

        // The library's listener assumes every item is a web tab; wrap it so "More"
        // opens the native sheet instead of switching navigators.
        bottomNav.setOnItemSelectedListener { item ->
            if (item.itemId == MORE_ID) {
                showMoreSheet()
                false
            } else {
                switchTo(item.itemId)
                true
            }
        }
        bottomNav.setOnItemReselectedListener { item ->
            if (item.itemId != MORE_ID) findHost(item.itemId)?.navigator?.clearAll()
        }
    }

    private fun switchTo(index: Int) {
        val tab = tabs.getOrNull(index) ?: return
        bottomNav.performHapticFeedback(android.view.HapticFeedbackConstants.CONTEXT_CLICK)
        delegate.setCurrentNavigator(tab.second.configuration)
        tabs.forEachIndexed { i, _ -> findViewById<View>(HOST_IDS[i]).isVisible = i == index }
        updateTabBarVisibility(index)
    }

    private fun updateTabBarVisibility(index: Int) {
        val host = findHost(index) ?: return
        val atRoot = host.navController.previousBackStackEntry == null
        // The library controller owns the bar's visibility (keyboard / modal); drive it through it.
        tabController.visibility = if (atRoot) HotwireBottomNavigationController.Visibility.DEFAULT
        else HotwireBottomNavigationController.Visibility.HIDDEN
    }

    private fun findHost(index: Int) = tabs.getOrNull(index)?.let { delegate.findNavigatorHost(it.second.configuration.navigatorHostId) }

    // ── More sheet + store picker (spec §3.5) ────────────────────────────────

    private fun showMoreSheet() {
        val sheet = BottomSheetDialog(this)
        val list = layoutInflater.inflate(R.layout.sheet_more, null) as ViewGroup
        list.layoutDirection = findViewById<View>(R.id.root).layoutDirection
        val items = list.findViewById<ViewGroup>(R.id.items)

        fun addRow(label: String, iconKey: String, onClick: () -> Unit) {
            val row = layoutInflater.inflate(R.layout.sheet_row, items, false) as TextView
            row.text = label
            row.setCompoundDrawablesRelativeWithIntrinsicBounds(Icons.forKey(iconKey), 0, 0, 0)
            row.setOnClickListener { sheet.dismiss(); onClick() }
            items.addView(row)
        }

        if (me.stores.size > 1) {
            val current = me.stores.firstOrNull { it.id == (me.store?.id ?: me.defaultStoreId) }
            addRow(getString(R.string.switch_store_current, current?.title ?: ""), "store") { showStorePicker() }
        }
        me.more.forEach { item ->
            val path = item.path ?: return@forEach
            addRow(item.label, item.icon) { delegate.currentNavigator?.route(AppConfig.url(path)) }
        }

        // The web header (with its Log out) is hidden in the app (S2), so sign-out is native.
        addRow(getString(R.string.logout), "logout") {
            MaterialAlertDialogBuilder(this)
                .setMessage(R.string.logout_confirm)
                .setPositiveButton(R.string.logout) { _, _ ->
                    LauncherActivity.restart(this, LauncherActivity.MODE_LOGOUT)
                    finish()
                }
                .setNegativeButton(R.string.cancel, null)
                .show()
        }

        sheet.setContentView(list)
        sheet.show()
    }

    private fun showStorePicker() {
        val currentId = me.store?.id ?: me.defaultStoreId
        val titles = me.stores.map { it.title }.toTypedArray()
        MaterialAlertDialogBuilder(this)
            .setTitle(R.string.switch_store)
            .setSingleChoiceItems(titles, me.stores.indexOfFirst { it.id == currentId }) { dialog, which ->
                dialog.dismiss()
                val picked = me.stores[which]
                if (picked.id != currentId) {
                    SecureStore.get(this).storeId = picked.id
                    LauncherActivity.restart(this) // fresh /me → new tabs, new session, push re-registered
                    finish()
                }
            }
            .setNegativeButton(android.R.string.cancel, null)
            .show()
    }

    companion object {
        private const val EXTRA_ME = "me"
        private const val EXTRA_LANDING = "landing"
        private const val EXTRA_UPDATE_AVAILABLE = "update_available"
        private const val STATE_TAB = "tab"
        private const val MORE_ID = 100
        private val HOST_IDS = listOf(R.id.tab_host_0, R.id.tab_host_1, R.id.tab_host_2, R.id.tab_host_3)

        fun intent(context: Context, meJson: String, landing: String, target: RouteTarget?, updateAvailable: Boolean) =
            Intent(context, MainActivity::class.java).apply {
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK)
                putExtra(EXTRA_ME, meJson)
                putExtra(EXTRA_LANDING, landing)
                putExtra(RouteTarget.EXTRA, target)
                putExtra(EXTRA_UPDATE_AVAILABLE, updateAvailable)
            }
    }
}
