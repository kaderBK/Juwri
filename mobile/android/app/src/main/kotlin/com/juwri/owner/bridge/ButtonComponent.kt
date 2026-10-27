package com.juwri.owner.bridge

import android.view.Menu
import android.view.MenuItem
import com.juwri.owner.shell.Icons
import dev.hotwire.core.bridge.BridgeComponent
import dev.hotwire.core.bridge.BridgeDelegate
import dev.hotwire.core.bridge.Message
import dev.hotwire.navigation.destinations.HotwireDestination
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * `button` (BRIDGE.md §3.3): a toolbar action. connect {title, iosImage, androidImage}
 * → an icon when `androidImage` maps to a drawable (title = accessibility label),
 * otherwise the title; tap → reply to connect (the web clicks the element).
 * Kept separate from the form's Save item (BRIDGE.md §3.4).
 */
class ButtonComponent(
    name: String,
    private val delegate: BridgeDelegate<HotwireDestination>,
) : BridgeComponent<HotwireDestination>(name, delegate) {

    override fun onReceive(message: Message) {
        when (message.event) {
            "connect" -> message.data<ConnectData>()?.let { show(it) }
            else -> Unit
        }
    }

    private fun show(data: ConnectData) {
        val toolbar = delegate.destination.toolbarForNavigation() ?: return
        toolbar.menu.removeItem(ITEM_ID)
        toolbar.menu.add(Menu.NONE, ITEM_ID, ORDER, data.title).apply {
            val icon = data.androidImage?.let { Icons.forKey(it) }?.takeIf { it != com.juwri.owner.R.drawable.ic_dot }
            if (icon != null) setIcon(icon)
            contentDescription = data.title
            setShowAsAction(MenuItem.SHOW_AS_ACTION_ALWAYS)
            setOnMenuItemClickListener { replyTo("connect") }
        }
    }

    @Serializable
    data class ConnectData(
        @SerialName("title") val title: String,
        @SerialName("iosImage") val iosImage: String? = null,
        @SerialName("androidImage") val androidImage: String? = null,
    )

    companion object {
        const val ITEM_ID = 0x4d42_0002
        const val ORDER = 998 // just before the form's Save (999)
    }
}
