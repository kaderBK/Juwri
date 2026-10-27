package com.juwri.owner

import android.net.Uri

/** Build-time configuration (app/build.gradle.kts). Never hard-code a host elsewhere. */
object AppConfig {
    val baseUrl: String = BuildConfig.BASE_URL.trimEnd('/')
    val host: String = Uri.parse(baseUrl).host.orEmpty()
    const val PLATFORM = "android"
    val version: String = BuildConfig.VERSION_NAME

    /** Spec §3.9 — the server keys `data-owner-app`, the logout marker and S4 off "JuwriApp/". */
    val userAgentPrefix: String = "JuwriApp/$version ($PLATFORM)"

    fun url(path: String): String = if (path.startsWith("http")) path else baseUrl + (if (path.startsWith("/")) path else "/$path")

    /** Same-host check used by routing, downloads and push/deep-link targets. */
    fun isAppUrl(url: String): Boolean {
        val u = Uri.parse(url)
        return (u.scheme == "https" || u.scheme == "http") && u.host.equals(host, ignoreCase = true)
    }
}
