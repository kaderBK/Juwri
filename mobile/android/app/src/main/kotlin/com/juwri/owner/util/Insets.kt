package com.juwri.owner.util

import android.view.View
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat

/** Pads a full-screen native view by the system bars + keyboard (edge-to-edge). */
fun View.applySystemBarInsets() {
    val start = paddingStart; val top = paddingTop; val end = paddingEnd; val bottom = paddingBottom
    ViewCompat.setOnApplyWindowInsetsListener(this) { v, insets ->
        val bars = insets.getInsets(WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.ime())
        v.setPaddingRelative(start + bars.left, top + bars.top, end + bars.right, bottom + bars.bottom)
        insets
    }
}
