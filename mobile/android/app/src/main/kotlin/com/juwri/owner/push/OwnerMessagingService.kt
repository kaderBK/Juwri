package com.juwri.owner.push

import android.app.PendingIntent
import android.content.Intent
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import com.juwri.owner.R
import com.juwri.owner.auth.LauncherActivity

/**
 * FCM (spec §3.6). Background / killed: the system shows the notification and a tap
 * opens [LauncherActivity] with the `data` keys as extras. Foreground: shown here
 * with the same extras. Either way the tap goes through the session check and
 * [com.juwri.owner.auth.RouteTarget] (url → there; order_id → Orders; else Home).
 */
class OwnerMessagingService : FirebaseMessagingService() {
    override fun onNewToken(token: String) {
        PushRegistrar.onNewToken(this, token)
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val title = message.notification?.title ?: message.data["title"] ?: getString(R.string.app_name)
        val body = message.notification?.body ?: message.data["body"].orEmpty()

        val tap = Intent(this, LauncherActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
            message.data.forEach { (k, v) -> putExtra(k, v) }
            putExtra("event", message.data["event"] ?: "push")
        }
        val pending = PendingIntent.getActivity(
            this, message.messageId?.hashCode() ?: 0, tap,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        val notification = NotificationCompat.Builder(this, getString(R.string.push_channel_id))
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setContentIntent(pending)
            .build()

        try {
            NotificationManagerCompat.from(this).notify(message.messageId?.hashCode() ?: System.currentTimeMillis().toInt(), notification)
        } catch (_: SecurityException) {
            // notification permission not granted
        }
    }
}
