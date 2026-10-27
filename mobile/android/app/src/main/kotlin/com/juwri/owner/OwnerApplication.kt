package com.juwri.owner

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import com.juwri.owner.bridge.ButtonComponent
import com.juwri.owner.bridge.FormComponent
import com.juwri.owner.bridge.MenuComponent
import com.juwri.owner.web.DownloadRouteDecisionHandler
import com.juwri.owner.web.LoginFragment
import com.juwri.owner.web.WebBottomSheetFragment
import com.juwri.owner.web.WebFragment
import dev.hotwire.core.bridge.BridgeComponentFactory
import dev.hotwire.core.bridge.KotlinXJsonConverter
import dev.hotwire.core.config.Hotwire
import dev.hotwire.core.logging.HotwireLogLevel
import dev.hotwire.core.turbo.config.PathConfiguration
import dev.hotwire.navigation.config.defaultFragmentDestination
import dev.hotwire.navigation.config.registerBridgeComponents
import dev.hotwire.navigation.config.registerFragmentDestinations
import dev.hotwire.navigation.config.registerRouteDecisionHandlers
import dev.hotwire.navigation.routing.AppNavigationRouteDecisionHandler
import dev.hotwire.navigation.routing.BrowserTabRouteDecisionHandler
import dev.hotwire.navigation.routing.SystemNavigationRouteDecisionHandler

class OwnerApplication : Application() {
    override fun onCreate() {
        super.onCreate()
        configureHotwire()
        createNotificationChannel()
    }

    private fun configureHotwire() {
        Hotwire.defaultFragmentDestination = WebFragment::class
        Hotwire.registerFragmentDestinations(
            WebFragment::class,
            WebBottomSheetFragment::class,
            LoginFragment::class,
        )

        // Exactly the components BRIDGE.md §3 specifies. This list ends up in the UA
        // ("bridge-components: [form menu button]") and decides what the web hides.
        Hotwire.registerBridgeComponents(
            BridgeComponentFactory("form", ::FormComponent),
            BridgeComponentFactory("menu", ::MenuComponent),
            BridgeComponentFactory("button", ::ButtonComponent),
        )

        // Downloads first (the web view's cookies are not shared with Custom Tabs),
        // then the app host in-app, other hosts in Custom Tabs, mailto/tel via the system.
        Hotwire.registerRouteDecisionHandlers(
            DownloadRouteDecisionHandler(),
            AppNavigationRouteDecisionHandler(),
            BrowserTabRouteDecisionHandler(),
            SystemNavigationRouteDecisionHandler(),
        )

        Hotwire.config.jsonConverter = KotlinXJsonConverter()
        Hotwire.config.applicationUserAgentPrefix = AppConfig.userAgentPrefix
        Hotwire.config.webViewDebuggingEnabled = BuildConfig.DEBUG
        Hotwire.config.logger.logLevel = if (BuildConfig.DEBUG) HotwireLogLevel.DEBUG else HotwireLogLevel.NONE

        Hotwire.loadPathConfiguration(
            context = this,
            location = PathConfiguration.Location(
                assetFilePath = "json/path-configuration.json",
                remoteFileUrl = AppConfig.url("/api/v1/app/path-configuration/android.json"),
            ),
        )
    }

    private fun createNotificationChannel() {
        val manager = getSystemService(NotificationManager::class.java) ?: return
        val channel = NotificationChannel(
            getString(R.string.push_channel_id),
            getString(R.string.push_channel_name),
            NotificationManager.IMPORTANCE_HIGH,
        )
        manager.createNotificationChannel(channel)
    }
}
