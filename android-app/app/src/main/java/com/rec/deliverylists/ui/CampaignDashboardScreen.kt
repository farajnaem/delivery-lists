package com.rec.deliverylists.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.rec.deliverylists.DeliveryApp
import com.rec.deliverylists.data.DeliveryRepository
import com.rec.deliverylists.data.local.BeneficiaryEntity
import com.rec.deliverylists.data.local.CampaignEntity
import com.rec.deliverylists.util.ArabicFormat
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.async

@Composable
fun CampaignDashboardScreen(
    campaignId: Int,
    onBack: () -> Unit,
) {
    val repo = DeliveryApp.repository
    val liveCampaign by repo.observeCampaign(campaignId).collectAsState(initial = null)
    var campaign by remember { mutableStateOf<CampaignEntity?>(null) }
    var preparing by remember { mutableStateOf(true) }
    var prepareProgress by remember { mutableFloatStateOf(0f) }
    var prepareStatus by remember { mutableStateOf("بدء التجهيز…") }
    var prepareError by remember { mutableStateOf<String?>(null) }
    var prepareAttempt by remember { mutableIntStateOf(0) }
    var elapsedSec by remember { mutableIntStateOf(0) }
    var syncing by remember { mutableStateOf(false) }
    var autoSyncHint by remember { mutableStateOf(false) }
    var delivering by remember { mutableStateOf(false) }
    var query by remember { mutableStateOf("") }
    var selected by remember { mutableStateOf<BeneficiaryEntity?>(null) }
    var confirmTarget by remember { mutableStateOf<BeneficiaryEntity?>(null) }
    val snackbar = remember { SnackbarHostState() }
    val recent by repo.observeRecent(campaignId).collectAsState(initial = emptyList())
    val late by repo.observeLate(campaignId).collectAsState(initial = emptyList())
    val scope = rememberCoroutineScope()
    val focus = LocalFocusManager.current

    // تحديث الرصيد/المخزن مباشرة عند وصول مزامنة من السيرفر
    LaunchedEffect(liveCampaign) {
        if (liveCampaign != null) {
            campaign = liveCampaign
        }
    }

    suspend fun toast(msg: String) {
        snackbar.showSnackbar(msg)
    }

    suspend fun refreshSelectedFromDb() {
        selected?.let { sel ->
            selected = repo.getBeneficiary(campaignId, sel.id) ?: sel
        }
    }

    fun doSearch() {
        scope.launch {
            focus.clearFocus()
            // مزامنة سريعة قبل البحث حتى تظهر حالة التسليم من الأجهزة الأخرى
            runCatching { repo.syncCampaign(campaignId) }
            campaign = repo.getCampaign(campaignId) ?: campaign
            val results = repo.search(campaignId, query, refreshFromServer = false)
            selected = results.firstOrNull()
            when {
                results.isEmpty() -> toast("لم يُعثر على مستفيد بهذا البحث")
                results.size > 1 -> toast("وُجد ${results.size} نتائج — عرض الأول. حدّد البحث أدق")
                selected?.receiptStatus == DeliveryRepository.STATUS_DELIVERED ->
                    toast("هذا المستفيد مُسلَّم مسبقاً")
            }
        }
    }

    // مزامنة تلقائية كل 5 ثوانٍ أثناء فتح شاشة التسليم — تعكس تسليمات الجوالات الأخرى
    LaunchedEffect(campaignId, preparing) {
        if (preparing) return@LaunchedEffect
        while (isActive) {
            delay(5_000)
            if (syncing || delivering) continue
            autoSyncHint = true
            runCatching {
                repo.syncCampaign(campaignId).getOrThrow()
                refreshSelectedFromDb()
            }
            autoSyncHint = false
        }
    }

    LaunchedEffect(preparing, prepareError) {
        if (!preparing || prepareError != null) return@LaunchedEffect
        elapsedSec = 0
        while (isActive && preparing && prepareError == null) {
            delay(1000)
            elapsedSec += 1
        }
    }

    LaunchedEffect(campaignId, prepareAttempt) {
        preparing = true
        prepareError = null
        prepareProgress = 0.02f
        prepareStatus = "قراءة بيانات الطرد…"
        campaign = repo.getCampaign(campaignId)
        val current = campaign
        if (current == null) {
            prepareError = "لم يُعثر على الطرد محلياً — حدّث القائمة ثم أعد المحاولة"
            preparing = false
            return@LaunchedEffect
        }

        if (!current.snapshotComplete) {
            prepareStatus = "جاري التنزيل من السيرفر (أول مرة)…"
            prepareProgress = 0.05f

            // نبض حي أثناء انتظار الشبكة حتى لا تبدو الشاشة معلّقة
            val downloadDeferred = async {
                repo.downloadSnapshot(campaignId) { fraction, message ->
                    prepareProgress = (fraction * 0.85f).coerceIn(0f, 0.85f)
                    prepareStatus = message
                }
            }
            while (isActive && !downloadDeferred.isCompleted) {
                delay(1000)
                if (!downloadDeferred.isCompleted && prepareProgress < 0.32f) {
                    prepareProgress = (prepareProgress + 0.01f).coerceAtMost(0.32f)
                    prepareStatus =
                        "جاري التنزيل من السيرفر… مضى ${formatElapsed(elapsedSec)} — لا تغلق التطبيق"
                }
            }
            val download = downloadDeferred.await()
            if (download.isFailure) {
                prepareError = download.exceptionOrNull()?.message
                    ?: "فشل تحميل بيانات الطرد"
                preparing = false
                return@LaunchedEffect
            }
            campaign = repo.getCampaign(campaignId)
            prepareStatus = "اكتمل التحميل — بدء المزامنة…"
            prepareProgress = 0.88f
        } else {
            prepareStatus = "البيانات جاهزة محلياً — مزامنة سريعة…"
            prepareProgress = 0.55f
        }

        val sync = repo.syncCampaign(campaignId) { fraction, message ->
            prepareProgress = (0.88f + fraction * 0.12f).coerceIn(0.88f, 1f)
            prepareStatus = message
        }
        campaign = repo.getCampaign(campaignId)
        if (sync.isFailure && campaign?.snapshotComplete != true) {
            prepareError = sync.exceptionOrNull()?.message ?: "فشلت المزامنة"
            preparing = false
            return@LaunchedEffect
        }
        prepareProgress = 1f
        prepareStatus = if (sync.isSuccess) "جاهز للعمل" else "يعمل دون اتصال — المزامنة لاحقاً"
        preparing = false
    }

    if (preparing || (campaign == null && prepareError == null)) {
        PrepareLoadingScreen(
            progress = prepareProgress,
            status = prepareStatus,
            elapsedSec = elapsedSec,
            onBack = onBack,
        )
        return
    }

    if (prepareError != null && (campaign == null || campaign?.snapshotComplete != true)) {
        PrepareErrorScreen(
            message = prepareError!!,
            onRetry = { prepareAttempt += 1 },
            onBack = onBack,
        )
        return
    }

    val c = campaign!!

    Box(Modifier.fillMaxSize()) {
        Column(
            Modifier
                .fillMaxSize()
                .padding(16.dp)
                .verticalScroll(rememberScrollState()),
        ) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                OutlinedButton(onClick = onBack) { Text("رجوع") }
                OutlinedButton(
                    onClick = {
                        scope.launch {
                            syncing = true
                            repo.syncCampaign(campaignId)
                                .onSuccess {
                                    campaign = repo.getCampaign(campaignId) ?: campaign
                                    refreshSelectedFromDb()
                                    toast("تمت المزامنة بنجاح")
                                }
                                .onFailure { toast(it.message ?: "فشلت المزامنة") }
                            syncing = false
                        }
                    },
                    enabled = !syncing,
                ) {
                    if (syncing) {
                        CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
                    } else {
                        Text("مزامنة")
                    }
                }
            }

            Spacer(Modifier.height(8.dp))
            Text(c.parcelName, style = MaterialTheme.typography.headlineSmall)
            Text(
                "${c.name} — ${c.warehouseName}",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Text(
                buildString {
                    append("آخر مزامنة: ${ArabicFormat.formatLastSync(c.lastSyncAt)}")
                    if (autoSyncHint) append(" — جاري التحديث…")
                },
                style = MaterialTheme.typography.labelLarge,
                color = MaterialTheme.colorScheme.primary,
            )
            if (!c.campaignActive) {
                Spacer(Modifier.height(6.dp))
                Text("تم إنهاء التسليم — الاستلام مغلق", color = MaterialTheme.colorScheme.error)
            }

            Spacer(Modifier.height(12.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                StatBox("الرصيد", c.balance.toString(), Modifier.weight(1f))
                StatBox("مُسلَّم", c.delivered.toString(), Modifier.weight(1f))
                StatBox("افتتاحي", c.openingQuantity.toString(), Modifier.weight(1f))
            }

            Spacer(Modifier.height(16.dp))
            Text("استعلام المستفيد", style = MaterialTheme.typography.titleMedium)
            Spacer(Modifier.height(8.dp))
            OutlinedTextField(
                value = query,
                onValueChange = { query = it },
                label = { Text("رقم تسلسلي / هوية / اسم") },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true,
                enabled = c.campaignActive,
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                keyboardActions = KeyboardActions(onSearch = { doSearch() }),
            )
            Spacer(Modifier.height(8.dp))
            Button(
                onClick = { doSearch() },
                modifier = Modifier.fillMaxWidth().height(48.dp),
                enabled = c.campaignActive && query.isNotBlank(),
            ) { Text("بحث") }

            selected?.let { b ->
                Spacer(Modifier.height(12.dp))
                BeneficiaryCard(
                    b = b,
                    canDeliver = c.campaignActive && b.receiptStatus != DeliveryRepository.STATUS_DELIVERED,
                    onConfirm = { confirmTarget = b },
                )
            }

            Spacer(Modifier.height(20.dp))
            Text("متأخرون (${late.size})", style = MaterialTheme.typography.titleMedium)
            if (late.isEmpty()) {
                Text(
                    "لا يوجد متأخرون حالياً",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            } else {
                late.take(10).forEach { row ->
                    Text(
                        "${row.displayCode} — ${row.name} — ${row.deliveryDate ?: ""}",
                        style = MaterialTheme.typography.bodyMedium,
                    )
                }
            }

            Spacer(Modifier.height(16.dp))
            Text("سجل الاستلام", style = MaterialTheme.typography.titleMedium)
            if (recent.isEmpty()) {
                Text(
                    "لا يوجد استلام مسجّل بعد",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            } else {
                recent.take(15).forEach { row ->
                    Text(
                        "${row.displayCode} — ${row.name} — ${ArabicFormat.formatDateTime(row.deliveredAt)}",
                        style = MaterialTheme.typography.bodyMedium,
                    )
                }
            }
            Spacer(Modifier.height(24.dp))
        }

        SnackbarHost(
            hostState = snackbar,
            modifier = Modifier.align(Alignment.BottomCenter).padding(16.dp),
        ) { data -> FeedbackSnackbar(data) }
    }

    confirmTarget?.let { target ->
        AlertDialog(
            onDismissRequest = { confirmTarget = null },
            title = { Text("تأكيد الاستلام") },
            text = {
                Text("تأكيد تسليم الطرد لـ «${target.name}»\nالكود: ${target.displayCode}")
            },
            confirmButton = {
                Button(
                    onClick = {
                        val b = target
                        confirmTarget = null
                        scope.launch {
                            delivering = true
                            repo.confirmDelivery(campaignId, b)
                                .onSuccess { result ->
                                    toast(
                                        if (result.online) {
                                            "تم تسجيل الاستلام على السيرفر فوراً"
                                        } else {
                                            "تم التسجيل محلياً — سيُزامن عند عودة الاتصال"
                                        },
                                    )
                                    campaign = repo.getCampaign(campaignId) ?: campaign
                                    selected = null
                                    query = ""
                                    // اسحب تحديثات الأجهزة الأخرى بعد التسليم
                                    if (result.online) {
                                        runCatching { repo.syncCampaign(campaignId) }
                                        campaign = repo.getCampaign(campaignId) ?: campaign
                                    }
                                }
                                .onFailure {
                                    toast(it.message ?: "تعذّر تسجيل الاستلام")
                                    // تحديث الحالة إن كان مُسلَّماً من جهاز آخر
                                    runCatching { repo.syncCampaign(campaignId) }
                                    campaign = repo.getCampaign(campaignId) ?: campaign
                                    selected = repo.getBeneficiary(campaignId, b.id)
                                }
                            delivering = false
                        }
                    },
                    enabled = !delivering,
                ) { Text("تأكيد") }
            },
            dismissButton = {
                TextButton(onClick = { confirmTarget = null }) { Text("إلغاء") }
            },
        )
    }
}

@Composable
private fun PrepareLoadingScreen(
    progress: Float,
    status: String,
    elapsedSec: Int,
    onBack: () -> Unit,
) {
    val pct = (progress * 100).toInt().coerceIn(0, 100)
    Box(Modifier.fillMaxSize().padding(24.dp)) {
        Column(
            Modifier.align(Alignment.Center).fillMaxWidth(),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text("تجهيز بيانات الطرد", style = MaterialTheme.typography.headlineSmall)
            Spacer(Modifier.height(8.dp))
            Text(
                status,
                style = MaterialTheme.typography.bodyLarge,
                textAlign = TextAlign.Center,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(20.dp))
            LinearProgressIndicator(
                progress = { progress.coerceIn(0.02f, 1f) },
                modifier = Modifier.fillMaxWidth().height(10.dp),
            )
            Spacer(Modifier.height(10.dp))
            Text(
                "$pct%",
                style = MaterialTheme.typography.titleLarge,
                color = MaterialTheme.colorScheme.primary,
            )
            Spacer(Modifier.height(6.dp))
            Text(
                "الوقت المنقضي: ${formatElapsed(elapsedSec)}",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(12.dp))
            Text(
                "التحميل الأول للعمليات الكبيرة قد يستغرق دقيقة أو أكثر — لا تغلق التطبيق.",
                style = MaterialTheme.typography.bodySmall,
                textAlign = TextAlign.Center,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        OutlinedButton(
            onClick = onBack,
            modifier = Modifier.align(Alignment.BottomCenter).padding(bottom = 8.dp),
        ) { Text("رجوع للقائمة") }
    }
}

@Composable
private fun PrepareErrorScreen(
    message: String,
    onRetry: () -> Unit,
    onBack: () -> Unit,
) {
    Box(Modifier.fillMaxSize().padding(24.dp), contentAlignment = Alignment.Center) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Text("تعذّر تجهيز الطرد", style = MaterialTheme.typography.headlineSmall)
            Spacer(Modifier.height(10.dp))
            Text(
                message,
                style = MaterialTheme.typography.bodyLarge,
                textAlign = TextAlign.Center,
                color = MaterialTheme.colorScheme.error,
            )
            Spacer(Modifier.height(20.dp))
            Button(onClick = onRetry, modifier = Modifier.fillMaxWidth()) { Text("إعادة المحاولة") }
            Spacer(Modifier.height(8.dp))
            OutlinedButton(onClick = onBack, modifier = Modifier.fillMaxWidth()) { Text("رجوع") }
        }
    }
}

private fun formatElapsed(sec: Int): String {
    val m = sec / 60
    val s = sec % 60
    return if (m > 0) "%d:%02d".format(m, s) else "$s ث"
}

@Composable
private fun StatBox(label: String, value: String, modifier: Modifier = Modifier) {
    Card(
        modifier = modifier,
        shape = RoundedCornerShape(12.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer),
    ) {
        Column(
            Modifier.padding(vertical = 12.dp).fillMaxWidth(),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(value, style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.onPrimaryContainer)
            Text(label, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onPrimaryContainer)
        }
    }
}

@Composable
private fun BeneficiaryCard(
    b: BeneficiaryEntity,
    canDeliver: Boolean,
    onConfirm: () -> Unit,
) {
    Card(
        Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(14.dp),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
    ) {
        Column(Modifier.padding(14.dp)) {
            Text(b.name, style = MaterialTheme.typography.titleMedium)
            Text("الكود: ${b.displayCode}")
            Text("الهوية: ${b.nationalId}")
            Text("الموعد: ${b.deliveryDate ?: "—"} — شباك ${b.windowNum?.toString() ?: "—"}")
            if (!b.timeFrom.isNullOrBlank() || !b.timeTo.isNullOrBlank()) {
                Text(
                    "الوقت: ${ArabicFormat.formatTime12(b.timeFrom)} — ${ArabicFormat.formatTime12(b.timeTo)}",
                )
            }
            Text("الحالة: ${b.receiptStatus}")
            if (canDeliver) {
                Spacer(Modifier.height(10.dp))
                Button(onClick = onConfirm, modifier = Modifier.fillMaxWidth().height(48.dp)) {
                    Text("تأكيد الاستلام")
                }
            } else if (b.receiptStatus == DeliveryRepository.STATUS_DELIVERED) {
                Spacer(Modifier.height(6.dp))
                Text("مُسلَّم مسبقاً", color = SuccessColor)
            }
        }
    }
}
