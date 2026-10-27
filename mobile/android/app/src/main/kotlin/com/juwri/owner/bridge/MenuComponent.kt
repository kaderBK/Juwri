package com.juwri.owner.bridge

import android.content.res.ColorStateList
import android.view.LayoutInflater
import android.view.ViewGroup
import android.widget.TextView
import com.google.android.material.bottomsheet.BottomSheetDialog
import com.google.android.material.color.MaterialColors
import com.juwri.owner.R
import dev.hotwire.core.bridge.BridgeComponent
import dev.hotwire.core.bridge.BridgeDelegate
import dev.hotwire.core.bridge.Message
import dev.hotwire.navigation.destinations.HotwireDestination
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * `menu` (BRIDGE.md §3.2): display {title, items[{title, index, destructive}], source}
 * → a native bottom sheet with a Cancel row; a pick replies `{selectedIndex: item.index}`
 * (the item's index, not its row — indexes can have gaps). Cancel sends nothing.
 */
class MenuComponent(
    name: String,
    private val delegate: BridgeDelegate<HotwireDestination>,
) : BridgeComponent<HotwireDestination>(name, delegate) {

    override fun onReceive(message: Message) {
        when (message.event) {
            "display" -> message.data<DisplayData>()?.let { show(it) }
            else -> Unit
        }
    }

    private fun show(data: DisplayData) {
        val context = delegate.destination.fragment.context ?: return
        val inflater = LayoutInflater.from(context)
        val sheet = BottomSheetDialog(context)
        val root = inflater.inflate(R.layout.sheet_more, null) as ViewGroup
        root.layoutDirection = delegate.destination.fragment.view?.rootView?.layoutDirection ?: root.layoutDirection
        val list = root.findViewById<ViewGroup>(R.id.items)

        if (data.title.isNotBlank()) {
            (inflater.inflate(R.layout.sheet_title, list, false) as TextView).also {
                it.text = data.title
                list.addView(it)
            }
        }

        val errorColor = MaterialColors.getColor(root, androidx.appcompat.R.attr.colorError)
        data.items.forEach { item ->
            val row = inflater.inflate(R.layout.sheet_row, list, false) as TextView
            row.text = item.title
            if (item.destructive) row.setTextColor(ColorStateList.valueOf(errorColor))
            row.setOnClickListener {
                sheet.dismiss()
                replyTo("display", SelectionData(item.index))
            }
            list.addView(row)
        }

        val cancel = inflater.inflate(R.layout.sheet_row, list, false) as TextView
        cancel.setText(R.string.cancel)
        cancel.setOnClickListener { sheet.dismiss() }
        list.addView(cancel)

        sheet.setContentView(root)
        sheet.show()
    }

    @Serializable
    data class DisplayData(
        @SerialName("title") val title: String = "",
        @SerialName("items") val items: List<Item> = emptyList(),
    )

    @Serializable
    data class Item(
        @SerialName("title") val title: String,
        @SerialName("index") val index: Int,
        @SerialName("destructive") val destructive: Boolean = false,
    )

    @Serializable
    data class SelectionData(@SerialName("selectedIndex") val selectedIndex: Int)
}
