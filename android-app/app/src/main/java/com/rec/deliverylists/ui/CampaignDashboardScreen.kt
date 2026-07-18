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
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import com.rec.deliverylists.DeliveryApp
import com.rec.deliverylists.data.DeliveryRepository
import com.rec.deliverylists.data.local.BeneficiaryEntity
import com.rec.deliverylists.data.local.CampaignEntity
import com.rec.deliverylists.util.ArabicFormat
import kotlinx.coroutines.launch

@Composable
fun CampaignDashboardScreen(
    campaignId: Int,
    onBack: () -> Unit,
) {
    val repo = DeliveryApp.repository
    var campaign by remember { mutableStateOf<CampaignEntity?>(null) }
    var preparing by remember { mutableStateOf(true) }
    var syncing by remember { mutableStateOf(false) }
    var query by remember { mutableStateOf("") }
    var selected by remember { mutableStateOf<BeneficiaryEntity?>(null) }
    var searchHits by remember { mutableStateOf(0) }
    var confirmTarget by remember { mutableStateOf<BeneficiaryEntity?>(null) }
    val snackbar = remember { SnackbarHostState() }
    val recent by repo.observeRecent(campaignId).collectAsState(initial = emptyList())
    val late by repo.observeLate(campaignId).collectAsState(initial = emptyList())
    val scope = rememberCoroutineScope()
    val focus = LocalFocusManager.current

    suspend fun toast(msg: String) {
        snackbar.showSnackbar(msg)
    }

    fun doSearch() {
        scope.launch {
            focus.clearFocus()
            val results = repo.search(campaignId, query)
            searchHits = results.size
            selected = results.firstOrNull()
            when {
                results.isEmpty() -> toast("لم يُعثر على مستفيد بهذا البحث")
                results.size > 1 -> toast("وُجد ${results.size} نتائج — عرض الأول. حدّد البحث أدق")
            }
        }
    }

    LaunchedEffect(campaignId) {
        preparing = true
        campaign = repo.getCampaign(campaignId)
        val current = campaign
        if (current != null && !current.snapshotComplete) {
            repo.downloadSnapshot(campaignId)
                .onFailure {
                    toast(it.message ?: "فشل تحميل بيانات الطرد")
                    preparing = false
                    return@LaunchedEffect
                }
            campaign = repo.getCampaign(campaignId)
            toast("تم تحميل بيانات الطرد بنجاح")
        }
        repo.syncCampaign(campaignId)
            .onSuccess { toast("تمت المزامنة مع السيرفر") }
            .onFailure { /* keep silent if offline after local load */ }
        campaign = repo.getCampaign(campaignId)
        preparing = false
    }

    if (preparing || campaign == null) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                CircularProgressIndicator()
                Spacer(Modifier.height(12.dp))
                Text("جاري تجهيز بيانات الطرد…")
                Text(
                    "قد يستغرق التحميل الأول قليلاً",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
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
                                    campaign = repo.getCampaign(campaignId)
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
                "آخر مزامنة: ${ArabicFormat.formatLastSync(c.lastSyncAt)}",
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
                Button(onClick = {
                    val b = target
                    confirmTarget = null
                    scope.launch {
                        repo.confirmDelivery(campaignId, b)
                            .onSuccess {
                                toast("تم تسجيل الاستلام — سيُزامن عند الاتصال")
                                campaign = repo.getCampaign(campaignId)
                                selected = null
                                query = ""
                                searchHits = 0
                            }
                            .onFailure { toast(it.message ?: "تعذّر تسجيل الاستلام") }
                    }
                }) { Text("تأكيد") }
            },
            dismissButton = {
                TextButton(onClick = { confirmTarget = null }) { Text("إلغاء") }
            },
        )
    }
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
