package com.juwri.owner.bridge

import android.view.LayoutInflater
import android.view.Menu
import android.view.MenuItem
import android.view.View
import com.google.android.material.button.MaterialButton
import com.juwri.owner.R
import dev.hotwire.core.bridge.BridgeComponent
import dev.hotwire.core.bridge.BridgeDelegate
import dev.hotwire.core.bridge.Message
import dev.hotwire.navigation.destinations.HotwireDestination
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * `form` (BRIDGE.md §3.1): a native Save button at the trailing end of the toolbar.
 * connect {submitTitle} → show/replace + enable; tap → reply to connect (the web
 * clicks its submit target); submitDisabled / submitEnabled toggle it.
 */
class FormComponent(
    name: String,
    private val delegate: BridgeDelegate<HotwireDestination>,
) : BridgeComponent<HotwireDestination>(name, delegate) {

    private var item: MenuItem? = null

    override fun onReceive(message: Message) {
        when (message.event) {
            "connect" -> message.data<ConnectData>()?.let { show(it.title) }
            "submitEnabled" -> setEnabled(true)
            "submitDisabled" -> setEnabled(false)
            else -> Unit // unknown events are ignored (the web may add more)
        }
    }

    private fun show(title: String) {
        val toolbar = delegate.destination.toolbarForNavigation() ?: return
        val view = LayoutInflater.from(toolbar.context).inflate(R.layout.toolbar_text_button, null)
        view.findViewById<MaterialButton>(R.id.toolbar_button).apply {
            text = title
            contentDescription = title
            setOnClickListener { replyTo("connect") }
        }
        toolbar.menu.removeItem(ITEM_ID)
        item = toolbar.menu.add(Menu.NONE, ITEM_ID, ORDER, title).apply {
            actionView = view
            setShowAsAction(MenuItem.SHOW_AS_ACTION_ALWAYS)
        }
    }

    private fun setEnabled(enabled: Boolean) {
        val view: View = item?.actionView ?: return
        view.findViewById<MaterialButton>(R.id.toolbar_button)?.isEnabled = enabled
    }

    @Serializable
    data class ConnectData(@SerialName("submitTitle") val title: String)

    companion object {
        const val ITEM_ID = 0x4d42_0001
        const val ORDER = 999 // rightmost (BRIDGE.md §3.4)
    }
}
