package com.juwri.owner.web

import android.content.ContentValues
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.provider.MediaStore
import android.webkit.CookieManager
import android.webkit.MimeTypeMap
import android.webkit.URLUtil
import android.widget.Toast
import androidx.core.content.FileProvider
import com.juwri.owner.AppConfig
import com.juwri.owner.R
import dev.hotwire.core.config.Hotwire
import dev.hotwire.core.turbo.webview.HotwireWebView
import dev.hotwire.navigation.activities.HotwireActivity
import dev.hotwire.navigation.navigator.NavigatorConfiguration
import dev.hotwire.navigation.routing.Router
import dev.hotwire.core.turbo.visit.VisitProposal
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.io.File
import java.util.concurrent.TimeUnit

/**
 * CSV / PDF exports inside the app (FORMS-AUDIT §5). Custom Tabs do NOT share the
 * web view's cookies (they would land on /login), so the file is fetched natively
 * with the web view's own session cookie + user agent and saved to Downloads.
 */
object Downloads {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    private val http = OkHttpClient.Builder().readTimeout(60, TimeUnit.SECONDS).build()

    private val exportPattern = Regex("(/export(-[a-z]+)?(/|$|\\?))|(/export-csv)|(\\.(csv|pdf|xlsx|zip)(\\?|$))|(/download(/|$|\\?))", RegexOption.IGNORE_CASE)

    fun isDownload(url: String): Boolean = AppConfig.isAppUrl(url) && exportPattern.containsMatchIn(Uri.parse(url).path.orEmpty() + (Uri.parse(url).query?.let { "?$it" } ?: ""))

    /** Links the page opens outside Turbo (download attribute, data-turbo=false). */
    fun attach(webView: HotwireWebView) {
        webView.setDownloadListener { url, _, contentDisposition, mimeType, _ ->
            if (AppConfig.isAppUrl(url)) download(webView.context, url, contentDisposition, mimeType)
        }
    }

    fun download(context: Context, url: String, contentDisposition: String? = null, mimeType: String? = null) {
        val app = context.applicationContext
        Toast.makeText(app, R.string.download_started, Toast.LENGTH_SHORT).show()
        scope.launch {
            val saved = withContext(Dispatchers.IO) {
                runCatching {
                    val request = Request.Builder().url(url)
                        .header("User-Agent", Hotwire.config.userAgentWithWebViewDefault(app))
                        .apply { CookieManager.getInstance().getCookie(url)?.let { header("Cookie", it) } }
                        .build()
                    http.newCall(request).execute().use { res ->
                        if (!res.isSuccessful) error("HTTP ${res.code}")
                        val type = res.header("Content-Type")?.substringBefore(';') ?: mimeType ?: "application/octet-stream"
                        if (type == "text/html") error("login page") // session lost: never save the HTML
                        val name = URLUtil.guessFileName(url, res.header("Content-Disposition") ?: contentDisposition, type)
                        save(app, name, type, res.body.bytes())
                    }
                }.getOrNull()
            }
            if (saved == null) {
                Toast.makeText(app, R.string.download_failed, Toast.LENGTH_LONG).show()
            } else {
                Toast.makeText(app, R.string.download_saved, Toast.LENGTH_SHORT).show()
                runCatching {
                    app.startActivity(Intent.createChooser(
                        Intent(Intent.ACTION_VIEW).setDataAndType(saved.first, saved.second)
                            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION), app.getString(R.string.download_open)
                    ).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                }
            }
        }
    }

    private fun save(context: Context, name: String, type: String, bytes: ByteArray): Pair<Uri, String> {
        val mime = type.ifBlank { MimeTypeMap.getSingleton().getMimeTypeFromExtension(name.substringAfterLast('.')) ?: "application/octet-stream" }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            val values = ContentValues().apply {
                put(MediaStore.Downloads.DISPLAY_NAME, name)
                put(MediaStore.Downloads.MIME_TYPE, mime)
                put(MediaStore.Downloads.IS_PENDING, 1)
            }
            val resolver = context.contentResolver
            val uri = resolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, values) ?: error("insert failed")
            resolver.openOutputStream(uri)!!.use { it.write(bytes) }
            values.clear(); values.put(MediaStore.Downloads.IS_PENDING, 0)
            resolver.update(uri, values, null, null)
            return uri to mime
        }
        val dir = context.getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS) ?: context.filesDir
        val file = File(dir, name).apply { writeBytes(bytes) }
        return FileProvider.getUriForFile(context, "${context.packageName}.downloads", file) to mime
    }
}

/** Export links followed by Turbo are visit proposals: download them instead of visiting. */
class DownloadRouteDecisionHandler : Router.RouteDecisionHandler {
    override val name = "download"

    override fun matches(proposal: VisitProposal, configuration: NavigatorConfiguration): Boolean =
        Downloads.isDownload(proposal.location)

    override fun handle(proposal: VisitProposal, configuration: NavigatorConfiguration, activity: HotwireActivity): Router.Decision {
        Downloads.download(activity, proposal.location)
        return Router.Decision.CANCEL
    }
}
