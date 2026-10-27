package com.juwri.owner.auth

import android.content.Intent
import android.os.Parcelable
import com.juwri.owner.AppConfig
import kotlinx.parcelize.Parcelize

/**
 * Where a push tap or an app link wants to go (spec §3.6 / §3.7):
 * `data.url` → that dashboard URL; else `data.order_id` → the Orders tab; else Home.
 * Always opened after the session check (LauncherActivity → MainActivity).
 */
@Parcelize
data class RouteTarget(val url: String? = null, val tabKey: String? = null) : Parcelable {
    companion object {
        const val EXTRA = "route_target"

        fun from(intent: Intent?): RouteTarget? {
            intent ?: return null
            @Suppress("DEPRECATION")
            (intent.getParcelableExtra<RouteTarget>(EXTRA))?.let { return it }

            // Verified app link (https://<host>/orders/…)
            intent.data?.toString()?.let { link ->
                if (intent.action == Intent.ACTION_VIEW && AppConfig.isAppUrl(link)) return RouteTarget(url = link)
            }

            // FCM: notification-message taps deliver `data` as intent extras; our own
            // foreground notifications use the same keys.
            val extras = intent.extras ?: return null
            val url = extras.getString("url")?.takeIf { it.isNotBlank() }
            if (url != null) {
                val absolute = if (url.startsWith("/")) AppConfig.url(url) else url
                if (AppConfig.isAppUrl(absolute)) return RouteTarget(url = absolute)
            }
            if (!extras.getString("order_id").isNullOrBlank()) return RouteTarget(tabKey = "orders")
            if (extras.containsKey("google.message_id") || extras.containsKey("event")) return RouteTarget(tabKey = "home")
            return null
        }
    }
}
