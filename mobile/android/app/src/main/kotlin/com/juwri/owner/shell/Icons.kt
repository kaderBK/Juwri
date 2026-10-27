package com.juwri.owner.shell

import com.juwri.owner.R

/** Maps the server's semantic `icon` keys (/me tabs + more, bridge buttons) to drawables. */
object Icons {
    fun forKey(key: String?): Int = when (key?.lowercase()) {
        "home", "house" -> R.drawable.ic_home
        "receipt", "orders", "order", "board", "clipboard" -> R.drawable.ic_receipt
        "book-open", "book", "menu-book", "catalog" -> R.drawable.ic_book
        "package", "box" -> R.drawable.ic_package
        "bed", "rooms" -> R.drawable.ic_bed
        "monitor", "kds" -> R.drawable.ic_monitor
        "star", "reviews" -> R.drawable.ic_star
        "bell", "notifications" -> R.drawable.ic_bell
        "smartphone", "mobile" -> R.drawable.ic_smartphone
        "chart", "reports", "bar-chart" -> R.drawable.ic_chart
        "calendar", "reservations", "bookings" -> R.drawable.ic_calendar
        "menu", "more", "grid" -> R.drawable.ic_menu
        "store", "shop" -> R.drawable.ic_store
        "add", "plus" -> R.drawable.ic_add
        "settings", "cog", "gear" -> R.drawable.ic_settings
        "users", "people", "customers" -> R.drawable.ic_people
        "user", "account" -> R.drawable.ic_person
        "logout", "sign-out" -> R.drawable.ic_logout
        else -> R.drawable.ic_dot
    }
}
