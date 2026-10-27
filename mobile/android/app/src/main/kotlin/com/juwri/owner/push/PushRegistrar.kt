package com.juwri.owner.push

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import com.juwri.owner.R
import com.juwri.owner.api.ApiException
import com.juwri.owner.api.OwnerApi
import com.juwri.owner.auth.SecureStore
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlin.coroutines.resume

/**
 * Spec §3.6: after login, on every cold start and on every store switch — native
 * pre-prompt → system permission → FCM token → POST /api/v1/app/devices. The
 * server addresses owner devices with the PLATFORM Firebase project (S8).
 */
object PushRegistrar {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)

    fun firebaseReady(context: Context): Boolean = FirebaseApp.getApps(context).isNotEmpty()

    fun onShellReady(activity: AppCompatActivity, storeId: Int?, pushEnabled: Boolean) {
        if (storeId == null || !pushEnabled || !firebaseReady(activity)) return
        val store = SecureStore.get(activity)

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(activity, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            if (store.pushPromptShown) return // asked once; the owner can enable it in system settings
            val launcher = activity.activityResultRegistry.register("push-permission", ActivityResultContracts.RequestPermission()) { granted ->
                if (granted) register(activity, storeId)
            }
            MaterialAlertDialogBuilder(activity)
                .setTitle(R.string.push_prompt_title)
                .setMessage(R.string.push_prompt_body)
                .setPositiveButton(R.string.push_prompt_allow) { _, _ -> launcher.launch(Manifest.permission.POST_NOTIFICATIONS) }
                .setNegativeButton(R.string.push_prompt_later, null)
                .setOnDismissListener { store.pushPromptShown = true }
                .show()
            return
        }
        register(activity, storeId)
    }

    fun register(context: Context, storeId: Int) {
        val app = context.applicationContext
        scope.launch {
            val token = fcmToken() ?: return@launch
            sendToken(app, storeId, token)
        }
    }

    /** FCM rotated the token (OwnerMessagingService.onNewToken). */
    fun onNewToken(context: Context, token: String) {
        val app = context.applicationContext
        val store = SecureStore.get(app)
        val storeId = store.storeId ?: return
        if (!store.hasToken()) return
        scope.launch { sendToken(app, storeId, token) }
    }

    private suspend fun sendToken(app: Context, storeId: Int, token: String) {
        try {
            OwnerApi(app).registerDevice(storeId, token)
            SecureStore.get(app).registeredPushToken = token
        } catch (e: ApiException) {
            // 403 feature_disabled → do not retry until the next cold start (spec §4).
        } catch (e: Exception) {
            // offline — the next cold start re-registers (it doubles as the heartbeat)
        }
    }

    private suspend fun fcmToken(): String? = suspendCancellableCoroutine { cont ->
        FirebaseMessaging.getInstance().token
            .addOnSuccessListener { cont.resume(it) }
            .addOnFailureListener { cont.resume(null) }
    }
}
