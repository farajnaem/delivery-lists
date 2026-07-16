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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
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
    var query by remember { mutableStateOf("") }
    var selected by remember { mutableStateOf<BeneficiaryEntity?>(null) }
    var message by remember { mutableStateOf<String?>(null) }
    val recent by repo.observeRecent(campaignId).collectAsState(initial = emptyList())
    val late by repo.observeLate(campaignId).collectAsState(initial = emptyList())
    val scope = rememberCoroutineScope()

    LaunchedEffect(campaignId) {
        preparing = true
        message = null
        campaign = repo.getCampaign(campaignId)
        val current = campaign
        if (current != null && !current.snapshotComplete) {
            repo.downloadSnapshot(campaignId)
                .onFailure {
                    message = it.message ?: "فشل تحميل بيانات الطرد"
                    preparing = false
                    return@LaunchedEffect
                }
            campaign = repo.getCampaign(campaignId)
        }
        repo.syncCampaign(campaignId)
        campaign = repo.getCampaign(campaignId)
        preparing = false
    }

    if (preparing || campaign == null) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                CircularProgressIndicator()
                Spacer(Modifier.height(12.dp))
                Text("جاري تحميل بيانات الطرد...")
            }
        }
        return
    }

    val c = campaign!!

    Column(
        Modifier.fillMaxSize().padding(16.dp).verticalScroll(rememberScrollState()),
    ) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            OutlinedButton(onClick = onBack) { Text("الطرود") }
            OutlinedButton(onClick = {
                scope.launch {
                    preparing = true
                    repo.downloadSnapshot(campaignId)
                        .onFailure { message = it.message }
                    repo.syncCampaign(campaignId)
                        .onSuccess { message = "تمت المزامنة" }
                        .onFailure { message = it.message }
                    campaign = repo.getCampaign(campaignId)
                    preparing = false
                }
            }) { Text("مزامنة") }
        }
        Text(c.parcelName, style = MaterialTheme.typography.headlineSmall)
        Text(
            "${c.name} — ${c.warehouseName}",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        if (!c.campaignActive) {
            Text("تم إنهاء التسليم", color = MaterialTheme.colorScheme.error)
        }
        Spacer(Modifier.height(8.dp))
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly) {
            StatBox("الرصيد", c.balance.toString())
            StatBox("مُسلَّم", c.delivered.toString())
            StatBox("افتتاحي", c.openingQuantity.toString())
        }
        message?.let { Text(it, Modifier.padding(vertical = 4.dp)) }
        Spacer(Modifier.height(12.dp))

        Text("استعلام المستفيد", style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(8.dp))
        OutlinedTextField(
            value = query,
            onValueChange = { query = it },
            label = { Text("رقم تسلسلي / هوية / اسم") },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
        )
        Button(
            onClick = {
                scope.launch {
                    val results = repo.search(campaignId, query)
                    selected = results.firstOrNull()
                    message = if (results.isEmpty()) "لم يُعثر على مستفيد" else null
                }
            },
            modifier = Modifier.fillMaxWidth(),
            enabled = c.campaignActive,
        ) { Text("بحث") }

        selected?.let { b ->
            Spacer(Modifier.height(8.dp))
            BeneficiaryCard(b) {
                scope.launch {
                    repo.confirmDelivery(campaignId, b)
                        .onSuccess {
                            message = "تم تسجيل الاستلام"
                            campaign = repo.getCampaign(campaignId)
                            selected = null
                            query = ""
                        }
                        .onFailure { message = it.message }
                }
            }
        }

        Spacer(Modifier.height(16.dp))
        Text("متأخرون (${late.size})", style = MaterialTheme.typography.titleMedium)
        late.take(10).forEach { row ->
            Text("${row.displayCode} — ${row.name} — ${row.deliveryDate ?: ""}")
        }
        Spacer(Modifier.height(12.dp))
        Text("سجل الاستلام", style = MaterialTheme.typography.titleMedium)
        recent.take(15).forEach { row ->
            Text("${row.displayCode} — ${row.name} — ${ArabicFormat.formatDateTime(row.deliveredAt)}")
        }
    }
}

@Composable
private fun StatBox(label: String, value: String) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Text(value, style = MaterialTheme.typography.titleLarge)
        Text(label, style = MaterialTheme.typography.bodySmall)
    }
}

@Composable
private fun BeneficiaryCard(b: BeneficiaryEntity, onConfirm: () -> Unit) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(12.dp)) {
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
            if (b.receiptStatus != DeliveryRepository.STATUS_DELIVERED) {
                Spacer(Modifier.height(8.dp))
                Button(onClick = onConfirm, modifier = Modifier.fillMaxWidth()) {
                    Text("تأكيد الاستلام")
                }
            }
        }
    }
}
