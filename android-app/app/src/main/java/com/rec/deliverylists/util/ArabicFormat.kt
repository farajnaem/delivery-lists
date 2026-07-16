package com.rec.deliverylists.util

object ArabicFormat {
    private const val WESTERN = "0123456789"
    private const val ARABIC = "٠١٢٣٤٥٦٧٨٩"

    fun toWestern(value: String): String = buildString(value.length) {
        value.forEach { ch ->
            val i = ARABIC.indexOf(ch)
            append(if (i >= 0) WESTERN[i] else ch)
        }
    }

    fun toArabic(value: String): String = buildString(value.length) {
        value.forEach { ch ->
            val i = WESTERN.indexOf(ch)
            append(if (i >= 0) ARABIC[i] else ch)
        }
    }

    fun formatTime12(time24: String?): String {
        val t = toWestern(time24.orEmpty().trim())
        val match = Regex("""^(\d{1,2}):(\d{2})""").find(t) ?: return toArabic(t)
        val hour = match.groupValues[1].toInt()
        val minute = match.groupValues[2]
        val period = if (hour < 12) "ص" else "م"
        var hour12 = hour % 12
        if (hour12 == 0) hour12 = 12
        return toArabic("$hour12:$minute $period")
    }

    fun formatDateTime(value: String?): String {
        val t = toWestern(value.orEmpty().trim())
        if (t.isEmpty()) return ""
        val parts = t.split(" ")
        return if (parts.size >= 2) {
            "${toArabic(parts[0])} ${formatTime12(parts[1])}"
        } else {
            toArabic(t)
        }
    }
}
