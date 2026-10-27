package com.juwri.owner.api

import android.content.Context
import android.os.Build
import android.webkit.CookieManager
import com.juwri.owner.AppConfig
import com.juwri.owner.auth.SecureStore
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import java.io.IOException
import java.util.concurrent.TimeUnit

/**
 * `/api/v1/app/…` client (spec §4). The bearer token is read from [SecureStore]
 * per call and never logged. Network failures surface as [IOException] (→ the
 * native offline screen), server errors as [ApiException].
 */
class OwnerApi(context: Context) {
    private val store = SecureStore.get(context)

    private val http = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(20, TimeUnit.SECONDS)
        .followRedirects(false)
        .followSslRedirects(false)
        .build()

    private val jsonType = "application/json".toMediaType()

    suspend fun config(): ConfigResponse =
        ApiJson.decodeFromString(get("/api/v1/app/config?platform=${AppConfig.PLATFORM}&version=${AppConfig.version}", auth = false))

    suspend fun login(username: String, password: String, otp: String?): LoginResponse {
        val body = buildJsonObject {
            put("username", username)
            put("password", password)
            put("device_id", store.deviceId)
            put("platform", AppConfig.PLATFORM)
            put("device_name", deviceName())
            put("app_version", AppConfig.version)
            if (!otp.isNullOrBlank()) put("otp", otp.trim())
        }
        return ApiJson.decodeFromString(post("/api/v1/app/auth/login", body, auth = false))
    }

    suspend fun me(storeId: Int?): MeResponse =
        ApiJson.decodeFromString(get("/api/v1/app/me" + (storeId?.let { "?store_id=$it" } ?: ""), auth = true))

    suspend fun session(to: String?, storeId: Int?): SessionResponse {
        val body = buildJsonObject {
            if (to != null) put("to", to)
            if (storeId != null) put("store_id", storeId)
        }
        return ApiJson.decodeFromString(post("/api/v1/app/session", body, auth = true))
    }

    suspend fun registerDevice(storeId: Int, pushToken: String) {
        post("/api/v1/app/devices", buildJsonObject {
            put("store_id", storeId)
            put("token", pushToken)
            put("platform", AppConfig.PLATFORM)
            put("device_name", deviceName())
            put("app_version", AppConfig.version)
            put("os_version", Build.VERSION.RELEASE)
        }, auth = true)
    }

    suspend fun logout(pushToken: String?) {
        post("/api/v1/app/auth/logout", buildJsonObject {
            if (pushToken != null) put("push_token", pushToken)
        }, auth = true)
    }

    /**
     * Spec §3.2 step 6, done natively: fetch the single-use hand-off URL without
     * following its redirect, and put the web session cookies it sets into the ONE
     * [CookieManager] every web view shares. Returns the redirect target (the
     * dashboard page to open), or null when the server bounced to /login.
     */
    suspend fun handoff(url: String): String? = withContext(Dispatchers.IO) {
        val request = Request.Builder().url(url)
            .header("User-Agent", AppConfig.userAgentPrefix)
            .header("Accept", "text/html")
            .build()
        http.newCall(request).execute().use { res ->
            val cookies = CookieManager.getInstance()
            res.headers("Set-Cookie").forEach { cookies.setCookie(AppConfig.baseUrl, it) }
            cookies.flush()
            val location = res.header("Location") ?: return@use null
            val target = AppConfig.url(location.removePrefix(AppConfig.baseUrl).ifEmpty { "/" })
            if (android.net.Uri.parse(target).path?.let { it == "/login" || it.endsWith("/login") } == true) null else target
        }
    }

    private suspend fun get(path: String, auth: Boolean): String = execute(
        Request.Builder().url(AppConfig.url(path)).get(), auth
    )

    private suspend fun post(path: String, body: JsonObject, auth: Boolean): String = execute(
        Request.Builder().url(AppConfig.url(path)).post(body.toString().toRequestBody(jsonType)), auth
    )

    private suspend fun execute(builder: Request.Builder, auth: Boolean): String = withContext(Dispatchers.IO) {
        builder.header("Accept", "application/json").header("User-Agent", AppConfig.userAgentPrefix)
        if (auth) {
            val token = store.readToken() ?: throw ApiException(401, "no_token", "Not signed in")
            builder.header("Authorization", "Bearer $token")
        }
        http.newCall(builder.build()).execute().use { res -> read(res) }
    }

    private fun read(res: Response): String {
        val text = res.body.string()
        if (res.isSuccessful) return text
        val err = runCatching { ApiJson.decodeFromString(ApiErrorBody.serializer(), text) }.getOrNull()
        throw ApiException(res.code, err?.code, err?.message ?: "HTTP ${res.code}")
    }

    companion object {
        fun deviceName(): String = listOf(Build.MANUFACTURER, Build.MODEL)
            .filter { it.isNotBlank() }.distinct().joinToString(" ").take(80)
    }
}
