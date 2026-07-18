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

    /** @deprecated يُبقى للتوافق — يعيد أرقاماً إنجليزية فقط */
    fun toArabic(value: String): String = toWestern(value)

    fun formatTime12(time24: String?): String {
        val t = toWestern(time24.orEmpty().trim())
        val match = Regex("""^(\d{1,2}):(\d{2})""").find(t) ?: return t
        val hour = match.groupValues[1].toInt()
        val minute = match.groupValues[2]
        val period = if (hour < 12) "ص" else "م"
        var hour12 = hour % 12
        if (hour12 == 0) hour12 = 12
        return "$hour12:$minute $period"
    }

    fun formatDateTime(value: String?): String {
        val t = toWestern(value.orEmpty().trim())
        if (t.isEmpty()) return ""
        val parts = t.split(" ")
        return if (parts.size >= 2) {
            "${parts[0]} ${formatTime12(parts[1])}"
        } else {
            t
        }
    }

    /** عرض نسبي لآخر مزامنة من timestamp بالميلي ثانية. */
    fun formatLastSync(atMillis: Long?): String {
        if (atMillis == null || atMillis <= 0L) return "لم تتم المزامنة بعد"
        val diff = System.currentTimeMillis() - atMillis
        if (diff < 0) return "الآن"
        val minutes = diff / 60_000L
        val hours = minutes / 60L
        val days = hours / 24L
        return when {
            minutes < 1 -> "الآن"
            minutes < 60 -> "منذ $minutes د"
            hours < 24 -> "منذ $hours س"
            days < 7 -> "منذ $days يوم"
            else -> {
                val sdf = java.text.SimpleDateFormat("yyyy-MM-dd HH:mm", java.util.Locale.US)
                sdf.format(java.util.Date(atMillis))
            }
        }
    }
}
